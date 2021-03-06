<?php

/**
* monitor
*
* @package monitor
* @copyright   2016 Uemanet
* @author      Lucas S. Vieira
*/

defined('MOODLE_INTERNAL') || die();

require_once('helper.php');

class local_monitor_external extends external_api
{
    private static $day = 60 * 60 * 24;

    /**
    * Returns default values for get_online_tutors_parameters
    * @return array
    */
    private static function get_online_time_default_parameters()
    {
        $startdate = new DateTime("NOW", core_date::get_server_timezone_object());
        $enddate = new DateTime("NOW", core_date::get_server_timezone_object());

        $enddate->add(new DateInterval('P7D'));

        return array(
            'time_between_clicks' => 60,
            'start_date' => $startdate->getTimestamp(),
            'end_date' => $enddate->getTimestamp()
        );
    }

    /**
    * Validate rules for get_online_tutors
    * @param $timeBetweenClicks
    * @param $startdate
    * @param $enddate
    * @return bool
    * @throws moodle_exception
    */
    private static function get_online_time_validate_rules($timeBetweenClicks, $startdate, $enddate)
    {
        $startdate = new DateTime($startdate, core_date::get_server_timezone_object());
        $enddate = new DateTime($enddate, core_date::get_server_timezone_object());

        $now = new DateTime("NOW", core_date::get_server_timezone_object());

        $start = $startdate->getTimestamp();
        $end = $enddate->getTimestamp();


        if (!($timeBetweenClicks > 0)) {
            throw new moodle_exception('timebetweenclickserror', 'local_monitor', null, null, '');
        }

        if ($start > $end) {
            throw new moodle_exception('startdateerror', 'local_monitor', null, null, '');
        }

        if ($end >= $now->getTimestamp()) {
            throw new moodle_exception('enddateerror', 'local_monitor', null, null, '');
        }

        return true;
    }

    /**
    * Returns description of get_online_time parameters
    * @return external_function_parameters
    */
    public static function get_online_time_parameters()
    {
        $default = local_monitor_external::get_online_time_default_parameters();

        return new external_function_parameters(array(
            'time_between_clicks' => new external_value(PARAM_INT, get_string('getonlinetime_param_time_between_clicks', 'local_monitor'), VALUE_DEFAULT, $default['time_between_clicks']),
            'start_date' => new external_value(PARAM_TEXT, get_string('getonlinetime_param_start_date', 'local_monitor'), VALUE_DEFAULT, $default['start_date']),
            'end_date' => new external_value(PARAM_TEXT, get_string('getonlinetime_param_end_date', 'local_monitor'), 'Data de fim da consulta: dd-mm-YYYY', VALUE_DEFAULT, $default['end_date']),
            'pes_id' => new external_value(PARAM_INT, get_string('getonlinetime_param_tutor_id', 'local_monitor'), 'ID do Tutor', VALUE_DEFAULT)
        ));
    }

    /**
    * Returns the time online day by day
    * @param $timeBetweenClicks
    * @param $startdate
    * @param $enddate
    * @param $tutorid
    * @return array
    * @throws Exception
    */
    public static function get_online_time($timeBetweenClicks, $startdate, $enddate, $tutorid)
    {
        global $DB;

        self::validate_parameters(self::get_online_time_parameters(), array(
            'time_between_clicks' => $timeBetweenClicks,
            'start_date' => $startdate,
            'end_date' => $enddate,
            'pes_id' => $tutorid
        ));

        local_monitor_external::get_online_time_validate_rules($timeBetweenClicks, $startdate, $enddate);

        $start = new DateTime($startdate, core_date::get_server_timezone_object());
        $end = new DateTime($enddate, core_date::get_server_timezone_object());

        $start = $start->getTimestamp();
        $end = $end->getTimestamp() + local_monitor_external::$day;

        $interval = $end - $start;
        $days = $interval / local_monitor_external::$day;

        try {
            $tutorGrupo = $DB->get_record('int_pessoa_user', array('pes_id' => $tutorid));

            if(!$tutorGrupo){
                throw new moodle_exception('tutornonexistserror', 'local_monitor', null, null, '');
            }

            $tutor = $DB->get_record('user', array('id' => $tutorGrupo->userid));
            $name = $tutor->firstname . ' ' . $tutor->lastname;
            $result = array('id' => $tutor->id, 'fullname' => $name, 'items' => array());

            for ($i = $days; $i > 0; $i--) {

                $parameters = array(
                    (integer)$tutor->id,
                    $end - local_monitor_external::$day * $i,
                    $end - local_monitor_external::$day * ($i - 1)
                );

                $query = "SELECT id, timecreated
                FROM {logstore_standard_log}
                WHERE userid = ?
                AND timecreated >= ?
                AND timecreated <= ?
                ORDER BY timecreated ASC";

                // Get user logs
                $logs = $DB->get_records_sql($query, $parameters);

                $date = new DateTime("NOW", core_date::get_server_timezone_object());
                $date->setTimestamp($end - (local_monitor_external::$day * $i));

                //$date = gmdate("d-m-Y", $end - (local_monitor_external::$day * $i));

                $previousLog = array_shift($logs);
                $previousLogTime = isset($previousLog) ? $previousLog->timecreated : 0;
                $sessionStart = isset($previousLog) ? $previousLog->timecreated : 0;
                $onlineTime = 0;

                foreach ($logs as $log) {
                    if (($log->timecreated - $previousLogTime) < $timeBetweenClicks) {
                        $onlineTime += $log->timecreated - $previousLogTime;
                        $sessionStart = $log->timecreated;
                    }

                    $previousLogTime = $log->timecreated;
                }

                $result['items'][] = array('onlinetime' => $onlineTime, 'date' => $date->format("d-m-Y"));
            }

        } catch (\Exception $e) {
            if(helper::debug()){
                throw $e;
            }
        }

        return $result;
    }

    /**
    * Returns description of get_online_time return values
    * @return external_function_parameters
     */
    public static function get_online_time_returns()
    {
        return new external_function_parameters(array(
            'id' => new external_value(PARAM_INT, get_string('getonlinetime_return_id', 'local_monitor')),
            'fullname' => new external_value(PARAM_TEXT, get_string('getonlinetime_return_fullname', 'local_monitor')),
            'items' => new external_multiple_structure(
                        new external_single_structure(array(
                            'onlinetime' => new external_value(PARAM_TEXT, get_string('getonlinetime_return_onlinetime', 'local_monitor')),
                            'date' => new external_value(PARAM_TEXT, get_string('getonlinetime_return_date', 'local_monitor'))
                        )
                    ))
            )
        );
    }
}
