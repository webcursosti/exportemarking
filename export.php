<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.


/**
 *
*
* @package    mod
* @subpackage emarking
* @copyright  2018 Mihail Pozarski (mihail.pozarski@uai.cl) 					
* @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
*/

//define('CLI_SCRIPT', true);
require_once(dirname(dirname(dirname(__FILE__))).'/config.php');
require_once ($CFG->libdir . '/tablelib.php');
require_once ($CFG->dirroot . "/mod/emarking/locallib.php");
require_once ($CFG->dirroot . "/mod/emarking/marking/locallib.php");
require_once ($CFG->dirroot . "/mod/emarking/print/locallib.php");
require_once ($CFG->dirroot . "/lib/externallib.php");
require_once ($CFG->dirroot . '/lib/excellib.class.php');
global $DB, $CFG;
// Validate that we have a rubric associated
$query = 'SELECT e.*, cm.id as coursemoduleid from mdl_course as c
inner join mdl_emarking as e ON c.id = e.course
inner join mdl_course_modules as cm on e.id = cm.instance AND cm.module = 36 where c.fullname LIKE "%Ecuaciones Diferenciales%"  AND c.shortname like "%MAT%" AND c.shortname like "%2-2018" AND e.name LIKE "%Prueba 2%" order by c.fullname';

$emarkings = $DB->get_records_sql($query);
$return = array();
foreach($emarkings as $emarking){
    $context = context_module::instance($emarking->coursemoduleid);
    list($gradingmanager, $gradingmethod, $definition, $rubriccontroller) =
    emarking_validate_rubric($context, false, false);
    // Calculate levels indexes in forced formative feedback (no grades)
    $levelsindex = array();
    foreach($definition->rubric_criteria as $crit) {
        $total = count($crit['levels']);
        $current = 0;
        foreach($crit['levels'] as $lvl) {
            $current++;
            $levelsindex[$lvl['id']] = $total - $current + 1;
        }
    }
    // Retrieve marking
    $csvsql = "
    		SELECT cc.fullname AS course,
    			e.name AS exam,
    			u.id,
    			u.idnumber,
    			u.lastname,
    			u.firstname,
    			cr.id criterionid,
    			cr.description,
    			l.id levelid,
    			IFNULL(l.score, 0) AS score,
    			IFNULL(c.bonus, 0) AS bonus,
    			IFNULL(l.score,0) + IFNULL(c.bonus,0) AS totalscore,
    			d.grade
    		FROM {emarking} e
    		INNER JOIN {emarking_submission} s ON (e.id = :emarkingid AND e.id = s.emarking)
    		INNER JOIN {emarking_draft} d ON (d.submissionid = s.id AND d.qualitycontrol=0)
            INNER JOIN {course} cc ON (cc.id = e.course)
    		INNER JOIN {user} u ON (s.student = u.id)
    		INNER JOIN {emarking_page} p ON (p.submission = s.id)
    		LEFT JOIN {emarking_comment} c ON (c.page = p.id AND d.id = c.draft AND c.levelid > 0)
    		LEFT JOIN {gradingform_rubric_levels} l ON (c.levelid = l.id)
    		LEFT JOIN {gradingform_rubric_criteria} cr ON (cr.id = l.criterionid)
    		ORDER BY cc.fullname ASC, e.name ASC, u.lastname ASC, u.firstname ASC, cr.sortorder";
    // Get data and generate a list of questions.
    $rows = $DB->get_recordset_sql($csvsql, array(
        'emarkingid' => $emarking->id));
    // Make a list of all criteria
    $questions = array();
    foreach ($rows as $row) {
        if (array_search($row->description, $questions) === false && $row->description) {
            $questions [] = $row->description;
        }
    }
    // Starting the loop
    $current = 0;
    $laststudent = 0;
    // Basic headers that go everytime
    $headers = array(
        '00course' => get_string('course'),
        '01exam' => get_string('exam', 'mod_emarking'),
        '02idnumber' => get_string('idnumber'),
        '03lastname' => get_string('lastname'),
        '04firstname' => get_string('firstname'));
    $tabledata = array();
    $data = null;
    // Get dataset again
    $rows = $DB->get_recordset_sql($csvsql, array(
        'emarkingid' => $emarking->id));
    // Now iterate through students
    $studentname = '';
    $lastrow = null;
    foreach ($rows as $row) {
        // The index allows to sort final grade at the end (99grade)
        $index = 10 + array_search($row->description, $questions);
        $keyquestion = $index . "" . $row->description;
        // If the index is not there yet we create it
        if (! isset($headers [$keyquestion]) && $row->description) {
            $headers [$keyquestion] = $row->description;
        }
        // If we changed student
        if ($laststudent != $row->id) {
            if ($laststudent > 0) {
                $tabledata [$studentname] = $data;
                $current ++;
            }
            $data = array(
                '00course' => $row->course,
                '01exam' => $row->exam,
                '02idnumber' => $row->idnumber,
                '03lastname' => $row->lastname,
                '04firstname' => $row->firstname);
            // If it's not formative feedback, add the grade as a final column
            if(!isset($CFG->emarking_formativefeedbackonly) || !$CFG->emarking_formativefeedbackonly) {
                $data['99grade'] = $row->grade;
            }
            $laststudent = intval($row->id);
            $studentname = $row->lastname . ',' . $row->firstname;
        }
        // Store the score (including bonus) or level index in criterion
        if ($row->description) {
            if(isset($CFG->emarking_formativefeedbackonly) && $CFG->emarking_formativefeedbackonly) {
                $data [$keyquestion] = $levelsindex[$row->levelid];
            } else {
                $data [$keyquestion] = $row->totalscore;
            }
        }
        $lastrow = $row;
    }
    // Add the last row
    $studentname = $lastrow->lastname . ',' . $lastrow->firstname;
    $tabledata [$studentname] = $data;
    // Add the grade if it's summative feedback
        $headers ['99grade'] = get_string('grade');
    ksort($tabledata);
    // Now pivot the table to form the Excel report
    $current = 0;
    $newtabledata = array();
    foreach ($tabledata as $data) {
        foreach ($questions as $q) {
            $index = 10 + array_search($q, $questions);
            if (! isset($data [$index . "" . $q])) {
                $data [$index . "" . $q] = '0.000';
            }
        }
        ksort($data);
        $current ++;
        $newtabledata [] = $data;
    }
    $return = array_merge($return,$newtabledata);
    // The file name of the report
    $excelfilename = clean_filename($emarking->name . "-grades.xls");
}
// Save the data to Excel
emarking_save_data_to_excel($headers, $return, $excelfilename, 5);