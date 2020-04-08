<?php

namespace App\Service;

use Symfony\Component\Dotenv\Dotenv;
use App\Service\SakaiWebService;
use App\Service\OCRestService;
use App\Entity\HashableInterface;
use App\Entity\Course;
use App\Entity\Department;
use App\Entity\Workflow;
use App\Entity\OpencastSeries;

class Utilities
{
    private $dbh;

    public function __construct($dbh = null) {
        if (is_null($dbh)) {
            $this->connectLocally();
        }
        else {
            $this->dbh = $dbh;
        }
    }

    public function refreshCourses() {
        try {
            $workflow = (new Workflow)->getWorkflow();

            $qry = "select distinct `sn`.course_code, `ps`.term, `ps`.dept,
                    if(`sn`.course_code REGEXP '". Course::SEM1 ."', 's1', if(`sn`.course_code REGEXP '". Course::SEM2 ."', 's2', 's0')) as 'semester',
                    (select id from timetable.uct_workflow `w` where `w`.year = :year and `w`.semester = if(`sn`.course_code REGEXP '". Course::SEM1 ."', 's1', if(`sn`.course_code REGEXP '". Course::SEM2 ."', 's2', 's0'))) as workflow_id
                    FROM timetable.sn_timetable_versioned `sn`
                        inner join opencast_venues  `venue`  on `sn`.archibus_id =  `venue`.archibus_id
                        inner join ps_courses `ps` on `sn`.course_code = `ps`.course_code and `sn`.term = `ps`.term
                    WHERE
                        `sn`.term = :year
                        and `ps`.active = 1
                        and `ps`.acad_career = 'UGRD'
                        and `venue`.campus_code in (". Course::ELIGIBLE .")
                        and `sn`.instruction_type='Lecture'
                        and `sn`.course_code not in (select course_code from course_optout where year = :year)

                        order by `sn`.course_code";

            $stmt = $this->dbh->prepare($qry);
            $stmt->execute([
                ':year' => date('Y'),
            ]);

            $optoutQry = "insert into course_optout (course_code, year, dept, semester, workflow_id) values (:course, :year, :dept, semester, :workflow_id) on duplicate key update course_code = :course, dept = :dept, workflow_id = :workflow_id";
            $optoutStmt = $this->dbh->prepare($optoutQry);

            $updateResults = [
                'coursesFound' => $stmt->rowCount(),
                'coursesUpdated' => 0
            ];

            if ($stmt->rowCount() > 0) {
                while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    if ($row['workflow_id']) {
                        $optoutStmt->execute([
                            ':course' => $row['course_code'],
                            ':year' => $row['term'],
                            ':dept' => $row['dept'],
                            ':semester' => $row['semester'],
                            ':workflow_id' => $row['workflow_id']
                        ]);
                        $updateResults['coursesUpdated'] += $optoutStmt->rowCount();
                    }
                }
                //$this->updateVulaSites(); TODO
                //$this->updateOCSeries();  TODO
            }

            return $updateResults;
        } catch (\PDOException $e) {
            throw new \Exception($e->getMessage());
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }

    public function refreshDepartments() {
        /*
        UPDATE timetable.uct_dept t1
            INNER JOIN timetable.uct_dept t2 ON t2.dept = t1.dept
            SET t1.hod_eid = if (getUserEIDFromMail(t1.email) REGEXP '^-?[0-9]{8}$' > 0, getUserEIDFromMail(t1.email),  getUserEIDFromMail(SUBSTRING_INDEX(t1.alt_email, ',',1)))
        */
        try {
            $workflow = (new Workflow)->getWorkflow();

            $qry = "";

            $stmt = $this->dbh->prepare($qry);
            $stmt->execute([
                ':year' => date('Y'),
                ':this_year_half' => $yearHalf
            ]);

            $updateResults = [
                'coursesFound' => $stmt->rowCount(),
                'coursesUpdated' => 0
            ];

            if ($stmt->rowCount() > 0) {
                while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    $optoutStmt->execute([
                        ':course' => $row['course_code'],
                        ':year' => $row['term'],
                        ':dept' => $row['dept'],
                        ':workflow_id' => $workflow['oid']
                    ]);
                    $updateResults['coursesUpdated'] += $optoutStmt->rowCount();
                }
            }

            return $updateResults;
        } catch (\PDOException $e) {
            throw new \Exception($e->getMessage());
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }

    public function userVisibleHash(HashableInterface $hashable) {
        return substr($hashable->getFullHash(), 0, 6);
    }

    public function getMail($hash) {
        $workflow = new Workflow();
        $worfklow_details = $workflow->getWorkflow();

        // Get real course/dept hash - select first in current batch
        if ($this->startsWith($hash,'zzzc') || $this->startsWith($hash,'zzzd')) {
            $hash = $this->getOptOutTestHash($worfklow_details['oid'], $this->startsWith($hash,'zzzc'));
        }

        $result = [ 'success' => 1, 'result' => null ];
        try {
            $query = "select mail.dept, mail.course, mail.state, mail.created_at, mail.name, mail.type, mail.case, :hash as hash,
                        `workflow`.`year`, `workflow`.`status`, `workflow`.`date_start`, `workflow`.`date_dept`, `workflow`.`date_course`, `workflow`.`date_schedule`
                        from uct_workflow_email mail
                        left join `uct_workflow` `workflow` on `mail`.`workflow_id` = `workflow`.`id`
                        where `workflow`.`year` = :year and hash = :hash order by created_at desc limit 1"; // and workflow_id = :workflow_id
            $stmt = $this->dbh->prepare($query);
            $stmt->execute([':hash' => $hash, ':year' => $worfklow_details['year']]); // ':workflow_id' => $worfklow_details['oid']
            if ($stmt->rowCount() === 0) {
                $result = [
                    'success' => 0,
                    'err' => 'The reference was not found, please contact <a href="mailto:help@vula.uct.ac.za?subject=Automated Setup of Lecture Recording (REF: '.$hash.')&body=Hi Vula Help Team,%0D%0A%0D%0AThe view page with the reference ('.$hash.') returns an error.%0D%0A%0D%0APlease fix this and get back to me.%0D%0A%0D%0AThanks you,%0D%0A" title="Help at Vula">help@vula.uct.ac.za</a>.'];
            }

            $result['result'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            $result = [ 'success' => 0, 'err' => $e->getMessage()];
        }

        return $result;
    }

    public function getAllCourses($year = '') {

        $workflow = new Workflow();
        $worfklow_details = $workflow->getWorkflow();

        $result = [ 'success' => true, 'result' => null ];
        try {
            $query = "select A.*, A.hod_eid as eid, B.*,
                    (SELECT count(distinct(`ps`.course_code))
                        FROM timetable.ps_courses `ps`
                            join timetable.course_optout `out` on `ps`.course_code = `out`.course_code and `ps`.term = `out`.year
                            left join timetable.sn_timetable_versioned `sn` on `sn`.course_code = `ps`.course_code and `sn`.term = `ps`.term
                            left join timetable.opencast_venues `venue` on `sn`.archibus_id =  `venue` .archibus_id
                        WHERE
                            `ps`.dept = A.dept and `ps`.term = B.year
                            and `ps`.active = 1
                            and `ps`.acad_career = 'UGRD'
                            and  `venue` .campus_code in (". Course::ELIGIBLE .")
                            and `sn`.instruction_type='Lecture'
                            and `ps`.course_code REGEXP '". Course::SEM1 ."'
						having (max(`sn`.tt_version)) ) as eligble_courses_S1,
                    (SELECT count(distinct(`ps`.course_code))
                        FROM timetable.ps_courses `ps`
                            join timetable.course_optout `out` on `ps`.course_code = `out`.course_code and `ps`.term = `out`.year
                            left join timetable.sn_timetable_versioned `sn` on `sn`.course_code = `ps`.course_code and `sn`.term = `ps`.term
                            left join timetable.opencast_venues `venue` on `sn`.archibus_id =  `venue` .archibus_id
                        WHERE
                            `ps`.dept = A.dept and `ps`.term = B.year
                            and `ps`.active = 1
                            and `ps`.acad_career = 'UGRD'
                            and  `venue` .campus_code in (". Course::ELIGIBLE .")
                            and `sn`.instruction_type='Lecture'
                            and `ps`.course_code REGEXP '". Course::SEM2 ."'
						having (max(`sn`.tt_version)) ) as eligble_courses_S2,
                (SELECT count(*) FROM timetable.uct_workflow_email mail where mail.dept=A.dept and mail.term=:year and mail.state = 0 and course REGEXP '". Course::SEM1 ."') as s1_mail_unsent,
                (SELECT count(*) FROM timetable.uct_workflow_email mail where mail.dept=A.dept and mail.term=:year and mail.state = 0 and course REGEXP '". Course::SEM1 ."') as s1_mail_unsent,
                (SELECT count(*) FROM timetable.uct_workflow_email mail where mail.dept=A.dept and mail.term=:year and mail.state = 1 and `type` = 'notification' and course REGEXP '". Course::SEM1 ."') as s1_mail_sent_note,
                (SELECT count(*) FROM timetable.uct_workflow_email mail where mail.dept=A.dept and mail.term=:year and mail.state = 1 and `type` = 'confirm' and course REGEXP '". Course::SEM1 ."') as s1_mail_sent_confirm,
                (SELECT count(*) FROM timetable.uct_workflow_email mail where mail.dept=A.dept and mail.term=:year and mail.state = 2 and course REGEXP '". Course::SEM1 ."') as s1_mail_err,
                (SELECT count(*) FROM timetable.uct_workflow_email mail where mail.dept=A.dept and mail.term=:year and mail.state = 0 and course REGEXP '". Course::SEM2 ."') as s2_mail_unsent,
                (SELECT count(*) FROM timetable.uct_workflow_email mail where mail.dept=A.dept and mail.term=:year and mail.state = 1 and `type` = 'notification' and course REGEXP '". Course::SEM2 ."') as s2_mail_sent_note,
                (SELECT count(*) FROM timetable.uct_workflow_email mail where mail.dept=A.dept and mail.term=:year and mail.state = 1 and `type` = 'confirm' and course REGEXP '". Course::SEM2 ."') as s2_mail_sent_confirm,
                (SELECT count(*) FROM timetable.uct_workflow_email mail where mail.dept=A.dept and mail.term=:year and mail.state = 2 and course REGEXP '". Course::SEM2 ."') as s2_mail_err
                from timetable.uct_dept A left join timetable.dept_optout B on A.dept = B.dept
                where B.year = :year and A.exists = 1";

            $stmt = $this->dbh->prepare($query);

            if ($stmt->execute([':year' => $worfklow_details['year'], ':workflow_id' => $worfklow_details['oid']])) {
                if ($stmt->rowCount() === 0) {
                    $result = [ 'success' => false, 'err' => 'Query is empty', ':year' => $worfklow_details['year'], ':workflow_id' => $worfklow_details['oid']];
                }
            } else {
                $result = [ 'success' => false, 'err' => $stmt->errorInfo(), ':year' => $worfklow_details['year'], ':workflow_id' => $worfklow_details['oid']];
            }

            # dept, name, email, firstname, lastname, alt_email, use_dept, secret, year, is_optout
            $ar = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {

                $d = new Department($row['dept'], null, $worfklow_details['year'], true);
                $dept = $row;
                $dept['hash'] = $d->getHash();
                unset($dept['secret']);
                $dept['mails'] = $this->getDepartmentMails($worfklow_details['oid'], $row['dept']);
                array_push($ar, $dept);
            }
            $result['result'] = $ar;
        } catch (\PDOException $e) {
            $result = [ 'success' => false, 'err' => $e->getMessage()];
        }

        return $result;
    }

    private function getDepartmentMails($workflow_id, $dept) {
        $result = [ 'success' => 1, 'result' => null ];
        try {
            $query = "select * from uct_workflow_email where workflow_id = :workflow_id and dept = :dept and course is null";
            $stmt = $this->dbh->prepare($query);
            $stmt->execute([':workflow_id' => $workflow_id, ':dept' => $dept]);
            if ($stmt->rowCount() === 0) {
                $result = [ 'success' => 0 ];
            }

            $ar = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                array_push($ar, $row);
            }

            $result['result'] = $ar;
        } catch (\PDOException $e) {
            $result = [ 'success' => 0 ];
        }

        return $result;
    }

    public function getAuthorizedUsers($username) {
        $result = [ 'success' => 0, 'err' => 'Unauthorized ('.$username.')'];
        try {
            $query = "select `user`.`username`, `user`.`name`, `user`.`type`
                        from uct_authorized_users `user`
                        where `user`.`username` = :username limit 1";
            $stmt = $this->dbh->prepare($query);
            $stmt->execute([':username' => $username]);
            if ($stmt->rowCount() === 0) {
                $result['err'] = 'Unauthorized ('.$username.')';
            } else {
                $result['success'] = 1;
                $result['result'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                unset($result['err']);
            }
        } catch (\PDOException $e) {
            $result['err'] = $e->getMessage();
        }

        return $result;
    }

    public function getAllBatches() {
        $result = [ 'success' => 1, 'result' => null ];
        try {
            $query = "SELECT `id`, `status`, `date_last`, `date_start`, `date_scheduled`,`active` FROM timetable.opencast_retention_batch;";
            $stmt = $this->dbh->prepare($query);
            $stmt->execute();
            if ($stmt->rowCount() === 0) {
                $result = [
                    'success' => 0,
                    'err' => 'No Batches found'];
            }

            $result['result'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            $result = [ 'success' => 0, 'err' => $e->getMessage()];
        }

        return $result;
    }

    private function getSeriesCount($where = "", $arg = "") {
        $result = 0;
        $q = $this->dbh->prepare("select count(*) as cnt from `timetable`.`view_oc_series` `series`
            left join `timetable`.`opencast_series_hash` `hash` on `hash`.series_id = `series`.series
            left join `timetable`.`view_sakai_users` `user` on `user`.`eid` = `series`.username and `user`.status != 'test'
            $where");
        if ($q->execute($arg)) {
            $f = $q->fetch();
            $result = $f['cnt'];
        }
        return $result;
    }

    private function getSeriesCustomCount($val = "normal", $where = "", $a = "", $st = " `series`.`retention`=:v") {
        $result = 0;
        $w = $where = ($where == "" ? " where ": $where ." and ") . $st;
        $a[':v'] = $val;
        $q = $this->dbh->prepare("select count(*) as cnt from `timetable`.`view_oc_series` `series`
            left join `timetable`.`opencast_series_hash` `hash` on `hash`.series_id = `series`.series
            left join `timetable`.`view_sakai_users` `user` on `user`.`eid` = `series`.username and `user`.status != 'test'
            $where");
        if ($q->execute($a)) {
            $f = $q->fetch();
            $result = $f['cnt'];
        }
        return $result;
    }

    public function getAllSeries($offset = 0, $limit = 15, $sort_dir = 'asc', $sort_field = 'title', $filter = "", $ret = "all", $batch = 0, $act = "none") {

        $result = [ 'success' => true,
                    'result' => null,
                    "offset" => $offset,
                    "limit" => $limit,
                    "filter" =>  $filter,
                    "order" => $sort_field .",". $sort_dir,
                    "ret" => $ret,
                    "batch" => $batch,
                    "all" => 0, "normal" => 0, "long" => 0, "forever" => 0,
                    "total" => 0, "count" => 0
                ];
        try {
            $where = '';
            $arg = [];
            $query = "select `series`.id, `series`.series, `series`.title,
                        `series`.contributor, `series`.creator,
                        `series`.username, `user`.first_name, `user`.last_name,
                        `hash`.user_status,
                        `series`.created_date, `series`.first_recording, `series`.last_recording,
                        `series`.`count`, `series`.`archive_count`,
                        `series`.`retention`, `hash`.`retention` as hash_retention,
                        `series`.`modification_date`, `hash`.`updated_at` as hash_modification_date,
                        `hash`.batch, `hash`.active, `hash`.action,
                        (select count(*) from `timetable`.`opencast_retention_email` `email` where `email`.hash = `hash`.short_code) as 'mail_count'
                        from `timetable`.`view_oc_series` `series`
                        left join `timetable`.`opencast_series_hash` `hash` on `hash`.series_id = `series`.series
                        left join `timetable`.`view_sakai_users` `user` on `user`.`eid` = `series`.username and `user`.status != 'test'";
            if ($filter != "") {
                $where = " where (`series`.title like :text or `series`.username like :text or concat_ws(' ',`user`.first_name,`user`.last_name) like :text)";
                $arg[":text"] = '%'. $filter .'%';
            }
            if ($batch != 0) {
                $where = ($where == "" ? " where ": $where ." and ") ." `hash`.batch=:batch";
                $arg[":batch"] = $batch;
            }

            $result['all'] = $this->getSeriesCount($where, $arg);
            $result['normal'] = $this->getSeriesCustomCount("normal", $where, $arg);
            $result['long'] = $this->getSeriesCustomCount("long", $where, $arg);
            $result['forever'] = $this->getSeriesCustomCount("forever", $where, $arg);

            if ($ret != "all") {
                $where = ($where == "" ? " where ": $where ." and ") ." `series`.`retention`=:ret";
                $arg[":ret"] = $ret;
            }

            $result['action'] = $act;
            $result['state_ready'] = $this->getSeriesCustomCount("ready", $where, $arg, " `hash`.action=:v and `hash`.batch >= 1");
            $result['state_review'] = $this->getSeriesCustomCount("review", $where, $arg, " `hash`.action=:v and `hash`.batch >= 1");
            $result['state_done']  = $this->getSeriesCustomCount("done", $where, $arg, " `hash`.action=:v and `hash`.batch >= 1");
            $result['state_error'] = $this->getSeriesCustomCount("error", $where, $arg, " `hash`.action=:v and `hash`.batch >= 1");
            $result['state_empty'] = $this->getSeriesCustomCount("empty", $where, $arg, " `hash`.action=:v and `hash`.batch >= 1");

            if ($act != "none") {
                $where = ($where == "" ? " where ": $where ." and ") ." `hash`.action=:act and `hash`.batch >= 1";
                $arg[":act"] = $act;
            }

            switch ($sort_field) {
                case 'retention': $sort_field = 'retention'; break;
                case 'organizer': $sort_field = 'contributor'; break;
                case 'events': $sort_field = 'count'; break;
            }

            $query .= $where . " order by $sort_field $sort_dir LIMIT $limit OFFSET $offset";

            $result['query'] = str_replace("\n","",$query);
            $result['where'] = $where;
            $result['arg'] = $arg;

            $stmt = $this->dbh->prepare($query);
            if ($stmt->execute($arg)) {
                if ($stmt->rowCount() === 0) {
                    $result['success'] = false;
                    $result['err'] = 'Query is empty';
                }

                $ar = [];
                while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {

                    $r = $row;
                    $oc_series = new OpencastSeries($row['series']);
                    $r['hash'] = $oc_series->getHash();
                    array_push($ar, $r);
                }
                $result['total'] = $this->getSeriesCount($where, $arg);
                $result['count'] = $stmt->rowCount();
                $result['result'] = $ar;
            } else {
                $result['success'] = false;
                $result['err'] = $stmt->errorInfo();
            }
        } catch (\PDOException $e) {
            $result['success'] = false;
            $result['err'] = $e->getMessage();
        }

        return $result;
    }

    public function getSeries($hash) {

        // Get real series hash - select first in current batch
        // if ($this->startsWith($hash,'zzzc') || $this->startsWith($hash,'zzzd')) {
        //     $hash = $this->getRetentionTestHash($worfklow_details['oid'], $this->startsWith($hash,'zzzc'));
        // }

        $result = [ 'success' => 1, 'result' => null ];
        try {
            $query = "select `hash`.series_id, `hash`.active, `series`.title, `series`.contributor, `series`.creator, `hash`.`action`,
                        `series`.username, `series`.retention, `hash`.batch, `series`.last_recording, `series`.count as 'no_recordings', :hash as `hash`
                        from `timetable`.`opencast_series_hash` `hash`
                        left join `timetable`.`view_oc_series` `series` on `hash`.series_id = `series`.series
                        where `hash`.short_code = :hash order by created_at desc limit 1";
            $stmt = $this->dbh->prepare($query);
            $stmt->execute([':hash' => $hash]);
            if ($stmt->rowCount() === 0) {
                $result = [
                    'success' => 0,
                    'err' => 'The reference was not found, please contact <a href="mailto:help@vula.uct.ac.za?subject=Series Details (REF: '.$hash.')&body=Hi Vula Help Team,%0D%0A%0D%0AThe view page with the reference ('.$hash.') returns an error.%0D%0A%0D%0APlease fix this and get back to me.%0D%0A%0D%0AThanks you,%0D%0A" title="Help at Vula">help@vula.uct.ac.za</a>.'];
            }

            $result['result'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            $result = [ 'success' => 0, 'err' => $e->getMessage()];
        }

        return $result;
    }

    public function getSeriesById($series_id) {
        $result = [ 'success' => 1, 'result' => null ];
        try {
            $query = "select `hash`.series_id, `hash`.active, `series`.title, `series`.contributor, `series`.creator,
                        `series`.username, `series`.retention, `hash`.batch, `series`.last_recording, `series`.count as 'no_recordings'
                        from `timetable`.`view_oc_series` `series`
                        left join `timetable`.`opencast_series_hash` `hash` on `hash`.series_id = `series`.series
                        where `series`.series = :series_id order by created_at desc limit 1";
            $stmt = $this->dbh->prepare($query);
            $stmt->execute([':series_id' => $series_id]);
            if ($stmt->rowCount() === 0) {
                $result = [
                    'success' => 0,
                    'err' => 'The reference was not found, please contact <a href="mailto:help@vula.uct.ac.za?subject=Series Details (REF: '.$series_id.')&body=Hi Vula Help Team,%0D%0A%0D%0AThe view page with the reference ('.$series_id.') returns an error.%0D%0A%0D%0APlease fix this and get back to me.%0D%0A%0D%0AThanks you,%0D%0A" title="Help at Vula">help@vula.uct.ac.za</a>.'];
            }

            $result['result'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            $result = [ 'success' => 0, 'err' => $e->getMessage()];
        }

        return $result;
    }

    public function getSeriesEmails($hash) {
        $result = [ 'success' => 1, 'result' => null ];
        try {
            $qry = "SELECT `state`, `updated_at` as `sent`,`type`,`case`,`hash` FROM timetable.opencast_retention_email
                    where hash = :hash";
            $stmt = $this->dbh->prepare($qry);
            $stmt->execute([':hash' => $hash]);

            $result['result'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            return $result;
        } catch (\PDOException $e) {
            $result = [ 'success' => 0, 'err' => $e->getMessage()];
        }

        return $result;
    }

    public function addSeriesEmails($series_id, $hash, $batch_id, $name, $mail_to, $mail_cc){

        $insertQry = "insert into opencast_retention_email (batch_id, series_id, hash, name, mail_to, mail_cc)
                        VALUES (:batch_id, :series_id, :hash, :name, :mail_to, :mail_cc)";

        try {
            $insertStmt = $this->dbh->prepare($insertQry);
            $bind = [
                ':batch_id' => $batch_id,
                ':series_id' => $series_id,
                ':hash' => $hash,
                ':name' => $name,
                ':mail_to' => $mail_to,
                ':mail_cc' => $mail_cc
            ];
            $insertStmt->execute($bind);
            if ($insertStmt->rowCount() === 0) {
                return FALSE;
            }
        } catch (\PDOException $e) {
            return 'ERR:'. $e->getMessage();
        }

        return TRUE;
    }

    public function getLastNotificationRetentionEmail($hash) {
        $result = '';
        try {
            $qry = "SELECT updated_at FROM timetable.opencast_retention_email where `hash` = :hash and `type`='notification' limit 1";
            $stmt = $this->dbh->prepare($qry);
            $stmt->execute([':hash' => $hash]);

            $r = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            return $r[0]['updated_at'];
        } catch (\PDOException $e) {
            $result = '';
        }

        return $result;
    }

    public function getOptOutTestHash($workflow_id, $is_course = FALSE) {
        try {
            $query = "select `hash` from uct_workflow_email where course is null and workflow_id = :workflow_id and `hash` NOT LIKE 'zzz%' order by id limit 1";
            if ($is_course) {
                $query = "select `hash` from uct_workflow_email where course is not null and  workflow_id = :workflow_id and `hash` NOT LIKE 'zzz%' order by id limit 1";
            }
            $stmt = $this->dbh->prepare($query);
            $stmt->execute([':workflow_id' => $workflow_id]);

            if ($stmt->rowCount() > 0) {
                $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);

                return $result[0]['hash'];
            } else {
                return NULL;
            }
        } catch (\PDOException $e) {
            throw new \Exception($e->getMessage());
        }
    }

    // For Series details
    public function getRetentionTestHash($batch_id) {
        // try {
        //     $query = "select `hash` from uct_workflow_email where course is null and workflow_id = :workflow_id and `hash` NOT LIKE 'zzz%' limit 1";
        //     if ($is_course) {
        //         $query = "select `hash` from uct_workflow_email where course is not null and  workflow_id = :workflow_id and `hash` NOT LIKE 'zzz%' limit 1";
        //     }
        //     $stmt = $this->dbh->prepare($query);
        //     $stmt->execute([':workflow_id' => $workflow_id]);

        //     if ($stmt->rowCount() > 0) {
        //         $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        //         return $result[0]['hash'];
        //     } else {
        //         return NULL;
        //     }
        // } catch (\PDOException $e) {
        //     throw new \Exception($e->getMessage());
        // }
    }

    public function getSurveyResults($hash) {
        $result = [ 'success' => 1
            ,'survey_response' => null
            ,'survey_access_device' =>  null
            ,'survey_access_type' => null
            ,'survey_activities' => null
            ,'survey_engagement_conditions' => null
            ,'survey_engagement_hours' => null
        ];
        $var = [':faculty' => "HUM"];

        // survey_response
        try {
            $query = "SELECT count(*) as cnt, `cohort`.level as lvl
                FROM studentsurvey.results_valid `results`
                    left join studentsurvey.cohort `cohort` on `cohort`.EID = `results`.Q1_EID
                where `cohort`.facultyCode = :faculty
                group by `cohort`.level";

            $stmt = $this->dbh->prepare($query);
            $stmt->execute($var);
            if ($stmt->rowCount() === 0) {
                $result = [
                    'success' => 0,
                    'err' => 'The reference was not found, please contact <a href="mailto:help@vula.uct.ac.za?subject=Series Details (REF: '.$hash.')&body=Hi Vula Help Team,%0D%0A%0D%0AThe view page with the reference ('.$hash.') returns an error.%0D%0A%0D%0APlease fix this and get back to me.%0D%0A%0D%0AThanks you,%0D%0A" title="Help at Vula">help@vula.uct.ac.za</a>.'];
            }

            $result['survey_response'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            $result = [ 'success' => 0, 'err' => $e->getMessage()];
        }

        // survey_access_device
        try {
            $query = "SELECT 
                sum(`results`.Q3 REGEXP 'Laptop') as Laptop,
                sum(`results`.Q3 REGEXP 'Desktop computer') as Desktop,
                sum(`results`.Q3 REGEXP 'Smartphone') as Smartphone,
                sum(`results`.Q3 REGEXP 'Tablet') as Tablet,
                sum(`results`.Q3 REGEXP \"I don't have access to any device\") as Nothing,
                count(*) as cnt,
                `results`.Q3
                FROM studentsurvey.results_valid `results`
                    left join studentsurvey.cohort `cohort` on `cohort`.EID = `results`.Q1_EID
                where `cohort`.facultyCode = :faculty
                group by `results`.Q3
                order by `results`.Q3";

            $stmt = $this->dbh->prepare($query);
            $stmt->execute($var);
            if ($stmt->rowCount() === 0) {
                $result = [
                    'success' => 0,
                    'err' => 'The reference was not found, please contact <a href="mailto:help@vula.uct.ac.za?subject=Series Details (REF: '.$hash.')&body=Hi Vula Help Team,%0D%0A%0D%0AThe view page with the reference ('.$hash.') returns an error.%0D%0A%0D%0APlease fix this and get back to me.%0D%0A%0D%0AThanks you,%0D%0A" title="Help at Vula">help@vula.uct.ac.za</a>.'];
            }

            $result['survey_access_device'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            $result = [ 'success' => 0, 'err' => $e->getMessage()];
        }

        //survey_access_type
        try {
            $query = "SELECT 
                sum(`results`.Q5 REGEXP 'Mobile data') as Mobile,
                sum(`results`.Q5 REGEXP 'Wifi') as Wifi,
                sum(`results`.Q5 REGEXP 'Other') as Other,
                sum(`results`.Q5 REGEXP 'No access') as Nothing,
                count(*) as cnt,
                `results`.Q5
                FROM studentsurvey.results_valid `results`
                    left join studentsurvey.cohort `cohort` on `cohort`.EID = `results`.Q1_EID
                    where `cohort`.facultyCode = :faculty
                group by `results`.Q5
                order by `results`.Q5";

            $stmt = $this->dbh->prepare($query);
            $stmt->execute($var);
            if ($stmt->rowCount() === 0) {
                $result = [
                    'success' => 0,
                    'err' => 'The reference was not found, please contact <a href="mailto:help@vula.uct.ac.za?subject=Series Details (REF: '.$hash.')&body=Hi Vula Help Team,%0D%0A%0D%0AThe view page with the reference ('.$hash.') returns an error.%0D%0A%0D%0APlease fix this and get back to me.%0D%0A%0D%0AThanks you,%0D%0A" title="Help at Vula">help@vula.uct.ac.za</a>.'];
            }

            $result['survey_access_type'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            $result = [ 'success' => 0, 'err' => $e->getMessage()];
        }

        //survey_activities
        try {
            $query = "SELECT 
                sum(`results`.Q8 REGEXP \"Login to Vula, read announcements, join a chatroom\") as login_vula,
                sum(`results`.Q8 REGEXP \"Download a reading, notes or presentation from Vula\") as download,
                sum(`results`.Q8 REGEXP \"Search for and download learning or research materials online or through UCT Library\") as search,
                sum(`results`.Q8 REGEXP \"Download a lecture video\") as download_500,
                sum(`results`.Q8 REGEXP \"Play a lecture video online\") as stream,
                sum(`results`.Q8 REGEXP \"Voice call\") as voice,
                sum(`results`.Q8 REGEXP \"Live video call or meeting\") as video,
                sum(`results`.Q8 REGEXP \"I don't know\") as other,
                sum(`results`.Q8 = \"\") as n,
                count(*) as cnt,
                `results`.Q8
                FROM studentsurvey.results_valid `results`
                    left join studentsurvey.cohort `cohort` on `cohort`.EID = `results`.Q1_EID
                    where `cohort`.facultyCode = :faculty
                group by `results`.Q8
                order by `results`.Q8";

            $stmt = $this->dbh->prepare($query);
            $stmt->execute($var);
            if ($stmt->rowCount() === 0) {
                $result = [
                    'success' => 0,
                    'err' => 'The reference was not found, please contact <a href="mailto:help@vula.uct.ac.za?subject=Series Details (REF: '.$hash.')&body=Hi Vula Help Team,%0D%0A%0D%0AThe view page with the reference ('.$hash.') returns an error.%0D%0A%0D%0APlease fix this and get back to me.%0D%0A%0D%0AThanks you,%0D%0A" title="Help at Vula">help@vula.uct.ac.za</a>.'];
            }

            $result['survey_activities'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            $result = [ 'success' => 0, 'err' => $e->getMessage()];
        }

        //survey_engagement_conditions
        try {
            $query = "SELECT 
                sum(`results`.Q4 REGEXP \"I have a laptop or desktop computer that I can use whenever I need to\") as own_laptop_desktop,
                sum(`results`.Q4 REGEXP \"I have a laptop or desktop computer but share it with others so it's not always available\") as share_laptop_desktop,
                sum(`results`.Q4 REGEXP \"I share someone else's laptop or desktop computer, so it's not always available\") as borrow_laptop_desktop,
                sum(`results`.Q4 REGEXP \"I don't have a laptop or desktop computer that I can use\") as Nothing,
                count(*) as cnt,
                `results`.Q4
                FROM studentsurvey.results_valid `results`
                    left join studentsurvey.cohort `cohort` on `cohort`.EID = `results`.Q1_EID
                    where `cohort`.facultyCode = :faculty
                group by `results`.Q4
                order by `results`.Q4";

            $stmt = $this->dbh->prepare($query);
            $stmt->execute($var);
            if ($stmt->rowCount() === 0) {
                $result = [
                    'success' => 0,
                    'err' => 'The reference was not found, please contact <a href="mailto:help@vula.uct.ac.za?subject=Series Details (REF: '.$hash.')&body=Hi Vula Help Team,%0D%0A%0D%0AThe view page with the reference ('.$hash.') returns an error.%0D%0A%0D%0APlease fix this and get back to me.%0D%0A%0D%0AThanks you,%0D%0A" title="Help at Vula">help@vula.uct.ac.za</a>.'];
            }

            $result['survey_engagement_conditions'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            $result = [ 'success' => 0, 'err' => $e->getMessage()];
        }

        //survey_engagement_hours
        try {
            $query = "SELECT 
                count(`results`.Q7) as cnt,
                `results`.Q7
                FROM studentsurvey.results_valid `results`
                    left join studentsurvey.cohort `cohort` on `cohort`.EID = `results`.Q1_EID
                    where `cohort`.facultyCode = :faculty
                group by `results`.Q7
                order by `results`.Q7";

            $stmt = $this->dbh->prepare($query);
            $stmt->execute($var);
            if ($stmt->rowCount() === 0) {
                $result = [
                    'success' => 0,
                    'err' => 'The reference was not found, please contact <a href="mailto:help@vula.uct.ac.za?subject=Series Details (REF: '.$hash.')&body=Hi Vula Help Team,%0D%0A%0D%0AThe view page with the reference ('.$hash.') returns an error.%0D%0A%0D%0APlease fix this and get back to me.%0D%0A%0D%0AThanks you,%0D%0A" title="Help at Vula">help@vula.uct.ac.za</a>.'];
            }

            $result['survey_engagement_hours'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            $result = [ 'success' => 0, 'err' => $e->getMessage()];
        }        

        return $result;
    }


    // Function to check string starting with given substring
    function startsWith ($string, $startString)
    {
        $len = strlen($startString);
        return (substr($string, 0, $len) === $startString);
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
