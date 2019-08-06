<?php

namespace App\Entity;

use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Validator\Constraints as Assert;
use App\Entity\OpencastSeries;
use App\Service\OCRestService;
use App\Service\Utilities;

class OpencastRetentionBatch
{
    private $dbh = null;

    private $oid;
    private $status;
    private $active;
    /**
     * @Assert\DateTime()
     */
    private $date_last;
    /**
     * @Assert\DateTime()
     */
    private $date_scheduled;

    public function __construct($oid) {
        if (!$this->dbh) {
            $this->connectLocally();
        }

        try {
            $query = "select * from opencast_retention_batch where id = :oid";
            $stmt = $this->dbh->prepare($query);
            $stmt->execute([":oid" => $oid]);

            if ($stmt->rowCount() > 0) {
                $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);

                $this->oid = $result[0]['id'];
                $this->status = $result[0]['status'];
                $this->date_last = $result[0]['date_last'];
                $this->date_scheduled = $result[0]['date_scheduled'];
                $this->active = ($result[0]['active'] ? true : false);
            } else {
                $this->status = $stmt->rowCount();
            }
        } catch (\PDOException $e) {
            throw new \Exception($e->getMessage());
        }
    }

    /**
     * Get active Opencast Retention Batch
     */
    public function getBatch() {
        return [
            'oid' => $this->oid,
            'status' => $this->status,
            'date_last' => $this->date_last,
            'date_schedule' => $this->date_schedule,
            'active' => $this->active
        ];
    }

    /**
     * Run the batch
     */
    public function run() {

        $now = new \DateTime();
        $result = [ 'success' => 1 ];
        $result['result'] = 'running: '. $this->status;

        //ENUM('created', 'waiting', 'running', 'completed')
        switch($this->status) {
            case 'created':
                // So initialization of this batch
                try {
                    $response = $this->startBatch();
                    $result['response'] = $response;
                    if ($response) {

                        // > state: waiting
                        $this->setState('waiting');
                        $result['result'] = $result['result'] ." - switch to waiting";
                    } else {
                        $result = [ 'success' => 0, 'err' => 'Error starting batch'];
                    }

                } catch (\PDOException $e) {
                    $result = [ 'success' => 0, 'result' => $e->getMessage()];
                }
                break;
            case 'waiting':
                // (wait for start date)
                // if ($now->diff($this->date_start)->format('%R') == '-') {
                //     // > status : run
                //     $this->setState('run');
                //     $result['result'] = $result['result'] ." - switch to run";
                // } else {
                //     $result['result'] = $result['result'] ." - waiting for ". $this->date_start->format("Y-m-d H:i:s");
                // }
                break;
            case 'running':
                // (wait for ... date)
                // if ($now->diff($this->date_dept)->format('%R') == '-') {

                //     // Create email entries for each departments head, so mail can be sent to each
                //     $action = $this->createDepartmentMails();
                //     if ($action === 1) {

                //         // > state: dept
                //         $this->setState('dept');
                //         $result['result'] = $result['result'] ." - switch to dept";
                //     } else {
                //         $result = [ 'success' => 0, 'err' => $action];
                //     }
                // } else {
                //     $result['result'] = $result['result'] ." - waiting for ". $this->date_dept->format("Y-m-d H:i:s");
                // }
                break;
            case 'completed':
                // All done
                // set this one inactive
                /*
                try {
                    $query = "UPDATE uct_workflow SET active = 0 WHERE active = 1";
                    $stmt = $this->dbh->prepare($query);
                    $stmt->execute();
                    $this->active = false;

                    // TODO: create new workflow
                    /*
                    $query = "INSERT INTO uct_workflow (`year`,`date_start`,`date_dept`,`date_course`,`date_schedule`)
                    VALUES (2019,<{date_start: }>,<{date_dept: }>,<{date_course: }>,<{date_schedule: }>)";
                    $stmt = $this->dbh->prepare($query);
                    $stmt->execute();
                    $this->active = false;
                    *

                    $result['result'] = $result['result'] ." - New Workflow";
                } catch (\PDOException $e) {
                    $result = [ 'success' => 0, 'err' => $e->getMessage()];
                }
                */
                break;
            default:
                $result = [ 'success' => 0, 'err' => 'Error running batch'];
                break;
        }

        return $result;
    }

    /**
     * Start the batch
     */
    private function startBatch(){
        if (!$this->dbh) {
            $this->connectLocally();
        }

        $all_done = FALSE;
        try {
            $qry = "select series from `timetable`.`view_oc_series` `series`
                        where `series`.last_recording <= :date and  `series`.retention='normal'";

            $stmt = $this->dbh->prepare($qry);
            $stmt->execute([':date' => $this->date_last]);
            if ($stmt->rowCount() === 0) {
                throw new \Exception("no series in this batch");
            }
            $series_in_batch = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($series_in_batch as $series) {

                $updateQry = "INSERT INTO `opencast_series_hash`
                (`series_id`, `batch`) VALUES (:series,:batch)
                on duplicate key update series_id= :series, batch = :batch";

                try {
                    $updateStmt = $this->dbh->prepare($updateQry);
                    $bind = [':series' => $series['series'], ':batch' => $this->oid];
                    if ($updateStmt->execute($bind) === false){
                        throw new \Exception('conflict ['. $updateQry .']'. json_encode($bind));
                    }
                } catch (\PDOException $e) {
                    throw new \Exception($e->getMessage());
                }
                $all_done = TRUE;
            }

            $qry = "select series_id from `timetable`.`opencast_series_hash` `hash` where `hash`.short_code is null and `hash`.batch = :batch";
            $stmt = $this->dbh->prepare($qry);
            $stmt->execute([':batch' => $this->oid]);
            $series_in_batch = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($series_in_batch as $series) {
                $h = new OpencastSeries($series['series_id']);
                if ($h->getHash() == NULL) {
                    $all_done = FALSE;
                }
            }

        } catch (\PDOException $e) {
            return $e->getMessage();
        }
        return $all_done;
    }

    private function createDepartmentMails(){

        try {
            // get list of departments
            $query = "SELECT * FROM uct_dept where `use_dept` = 1";
            $stmt = $this->dbh->prepare($query);
            $stmt->execute();

            $ar = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {

                $dept = new Department($row['dept'], null, $this->year, true);

                array_push($ar, '('. $this->oid .',"'. $row['dept'] .'",'. $this->year .',"'. $row['email'] .'","'.
                        $row['alt_email'] .'","'. $dept->getHash() .'","'.
                        ( strlen($row['firstname']."".$row['lastname']) < 2 ? "Colleague" : $row['firstname'] ." ". $row['lastname']) .'")');
            }

            $insertQry = "INSERT INTO `uct_workflow_email` (`workflow_id`, `dept`, `term`, `mail_to`, `mail_cc`, `hash`, `name`) VALUES ". implode(',', $ar);

            $mailStmt = $this->dbh->prepare($insertQry);
            if (!$mailStmt->execute($ar)) {
                return $this->dbh->errorInfo();
            }
        } catch (\PDOException $e) {
            return $e->getMessage();
        }

        return 1;
    }

    private function createCourseMails(){
        try {
            // get list of courses - only eligible courses
            // $query = "SELECT distinct(`course`.course_code) as course_code, `course`.dept as dept
            //     FROM timetable.course_optout `course`
            //     left join timetable.ps_courses `ps` on `ps`.course_code =  `course`.course_code and `ps`.term = `course`.year
            //     left join timetable.sn_timetable_versioned `sn` on `sn`.course_code = `course`.course_code and `sn`.term = `course`.year
            //     left join timetable.dept_optout `deptout` on `course`.`dept` = `deptout`.`dept`
            //     left join timetable.uct_dept `dept` on `course`.`dept` = `dept`.`dept`
            //     left join timetable.opencast_venues on `sn`.archibus_id = opencast_venues.archibus_id
            //     where `dept`.use_dept = 1 and `deptout`.is_optOut = 0 and `ps`.active = 1
            //         and `ps`.acad_career = 'UGRD' and opencast_venues.campus_code in (". Course::ELIGIBLE .")";
            $query = "SELECT DISTINCT
                            `versioned`.`course_code` AS `course_code`,
                            `ps`.`dept`
                        FROM
                            `timetable`.`sn_timetable_versioned` `versioned`
                            JOIN `timetable`.`opencast_venues` `venues` ON `versioned`.`archibus_id` = `venues`.`archibus_id`
                            JOIN `timetable`.`ps_courses` `ps` ON
                                (`versioned`.`course_code` = `ps`.`course_code` AND `versioned`.`term` = `ps`.`term`)
                            LEFT JOIN `timetable`.`dept_optout` `deptout` ON
                                (`deptout`.`dept` = `ps`.`dept` AND `deptout`.`year` = `versioned`.`term`)
                            LEFT JOIN `timetable`.`uct_dept` `dept` ON `ps`.`dept` = `dept`.`dept`
                        WHERE
                            ((`versioned`.`term` = '2019')
                                AND (`dept`.use_dept = 1) and (`deptout`.is_optOut = 0)
                                AND (`versioned`.`instruction_type` IN ('Lecture' , 'Module'))
                                AND `versioned`.`tt_version` IN (SELECT
                                    MAX(`timetable`.`timetable_versions`.`version`)
                                FROM
                                    `timetable`.`timetable_versions`)
                                AND (`ps`.`course_code` REGEXP :codes)
                                AND (`venues`.`campus_code` IN ('UPPER' , 'MIDDLE'))
                                AND (`ps`.`acad_career` = 'UGRD'))
                        ORDER BY `versioned`.`course_code`";

            $stmt = $this->dbh->prepare($query);

            if ($this->semester == 's1') {
                $stmt->execute([':codes' => Course::SEM1]);
            } else {
                $stmt->execute([':codes' => Course::SEM2]);
            }

            $ar = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {

                $course = new Course($row['course_code'], null, $this->year, true);
                $details = $course->getDetails();
                $has_oc_series = $course->checkIsTimetabledInOC();

                if ($has_oc_series == false) {
                    $to = [ 'mail' => $details['convenor']['email'],
                            'name' => $details['convenor']['name']];

                    if ($to['mail'] == null) {
                        $dept = new Department($row['dept'], null, $this->year, true, true);
                        $dept_details = $dept->getDetails();

                        $to = [ 'mail' => ($details['convenor']['email'] == null ? $dept_details['mail'] : $details['convenor']['email']),
                                'name' => ($details['convenor']['email'] == null ? $dept_details['hod'] : $details['convenor']['name'])];

                        if (strlen($to['name']) < 2) {
                            $to['name'] = "Colleague";
                        }
                    }

                    array_push($ar, '('. $this->oid .',"'. $row['dept'] .'","'. $row['course_code'] .'","'.
                        $to['mail'] .'","'.
                        $course->getHash() .'","'.
                        $to['name'] .'",'.
                        $this->year .')');
               }
            }

            // test
            array_push($ar, '('. $this->oid .',"ZZZ","ZZZ1000S","stephen.marquard@uct.ac.za","zzz000","Stephen Marquard",'. $this->year .')');
            $insertQry = "INSERT INTO `uct_workflow_email` (`workflow_id`, `dept`, `course`, `mail_to`, `hash`, `name`, `term`) VALUES ". implode(',', $ar);
            //return $insertQry;

            $mailStmt = $this->dbh->prepare($insertQry);
            if (!$mailStmt->execute($ar)) {
                return $this->dbh->errorInfo();
            }
        } catch (\PDOException $e) {
            return $e->getMessage();
        }

        return 1;
    }

    private function setState($status) {
        try {
            $query = "UPDATE opencast_retention_batch SET status = :status WHERE id = :oid";
            $stmt = $this->dbh->prepare($query);
            $stmt->execute([':status' => $status, ':oid' => $this->oid]);
            $this->status = $status;
        } catch (\PDOException $e) {
            throw new \Exception($e->getMessage());
        }
    }

    private function connectLocally() {
        $dotenv = new DotEnv();
        $dotenv->load('.env');

        $dbhost = getenv('DB_HOST');
        $dbname = getenv('DB_NAME');
        $dbuser = getenv('DB_USER');
        $dbpass = getenv('DB_PASS');
        $dbport = getenv('DB_PORT');
        $dbopts = [
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC
        ];
        $this->dbh = new \PDO("mysql:host=$dbhost;dbname=$dbname;port=$dbport;charset=utf8mb4", $dbuser, $dbpass, $dbopts);
    }
}