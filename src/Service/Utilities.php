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
                            ':semester' => $workflow->semester,
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

    public function getUserEmail($eid) {
        try {
            $emailQry = "select EMAIL from vula_archive.SAKAI_USER_ARCHIVE where EID = :eid limit 1";
            $stmt = $this->dbh->prepare($emailQry);
            $stmt->execute([':eid' => $eid]);
            if ($stmt->rowCount() === 0) {
                throw new \Exception("no such user");
            }

            $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            return $result[0]['EMAIL'];
        } catch (\PDOException $e) {
            throw new Exception($e->getMessage());
        }
    }

    public function getCompleteUser($search) {
        try {
            $searchQry = "select EID, EMAIL from vula_archive.SAKAI_USER_ARCHIVE where (EID = :search or EMAIL = :search or USER_ID = :search) and TYPE != 'test' limit 1";
            $stmt = $this->dbh->prepare($searchQry);
            $stmt->execute([':search' => $search]);
            if ($stmt->rowCount() === 0) {
                throw new \Exception("no such user");
            }

            $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            return $result[0];
        } catch (\PDOException $e) {
            throw new Exception($e->getMessage());
        }
    }

    public function getMail($hash) {
        $workflow = new Workflow();
        $worfklow_details = $workflow->getWorkflow();

        $result = [ 'success' => 1, 'result' => null ];
        try {
            $query = "select mail.dept, mail.course, mail.state, mail.created_at, mail.name, mail.type, mail.case,
                        `workflow`.`year`, `workflow`.`status`, `workflow`.`date_start`, `workflow`.`date_dept`, `workflow`.`date_course`, `workflow`.`date_schedule`
                        from uct_workflow_email mail
                        left join `uct_workflow` `workflow` on `mail`.`workflow_id` = `workflow`.`id`
                        where hash = :hash order by created_at desc limit 1"; // and workflow_id = :workflow_id
            $stmt = $this->dbh->prepare($query);
            $stmt->execute([':hash' => $hash]); // ':workflow_id' => $worfklow_details['oid']
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

    public function getAllCourses($year = '')
    {

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
                where B.year = :year";

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

    private function getDepartmentMails($workflow_id, $dept)
    {
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

    private function getSeriesCount($where = "", $arg = "") {
        $result = 0;
        $q = $this->dbh->prepare("select count(*) as cnt from `timetable`.`view_oc_series` `series` $where");
        if ($q->execute($arg)) {
            $f = $q->fetch();
            $result = $f['cnt'];
        }
        return $result;
    }

    private function getSeriesRetentionCount($cycle = "normal", $where = "", $a = "") {
        $result = 0;
        $w = ($where == "" ? "where retention=:ret" : $where ." and retention=:ret");
        $a[':ret'] = $cycle;
        $q = $this->dbh->prepare("select count(*) as cnt from `timetable`.`view_oc_series` `series` $w");
        if ($q->execute($a)) {
            $f = $q->fetch();
            $result = $f['cnt'];
        }
        return $result;
    }

    public function getAllSeries($offset = 0, $limit = 15, $sort_dir = 'asc', $sort_field = 'title', $filter = "", $ret = "all") {

        $result = [ 'success' => true, 'result' => null, "offset" => $offset, "limit" => $limit, "filter" =>  $filter, "order" => $sort_field .",". $sort_dir ];
        try {
            $where = '';
            $arg = [];
            $query = "select `series`.id, `series`.series, `series`.title,
                `series`.contributor, `series`.creator, `series`.username,
                `series`.created_date, `series`.first_recording, `series`.last_recording,
                `series`.`count`, `series`.`archive_count`, `series`.`retention`
                from `timetable`.`view_oc_series` `series`";
            if ($filter != "") {
                $where = " where `series`.title like :text or `series`.contributor like :text or `series`.username like :text";
                $arg[":text"] = '%'. $filter .'%';
            }

            $result['ret'] = $ret;
            $result['all'] = $this->getSeriesCount($where, $arg);
            $result['normal'] = $this->getSeriesRetentionCount("normal", $where, $arg);
            $result['long'] = $this->getSeriesRetentionCount("long",$where, $arg);
            $result['forever'] = $this->getSeriesRetentionCount("forever",$where, $arg);

            if ($ret != "all") {
                $where = ($where == "" ? "where retention=:ret" : $where ." and retention=:ret");
                $arg[":ret"] = $ret;
            }

            switch ($sort_field) {
                case 'retention': $sort_field = 'retention'; break;
                case 'organizer': $sort_field = 'contributor'; break;
                case 'events': $sort_field = 'count'; break;
            }

            $query .= $where . " order by $sort_field $sort_dir LIMIT $limit OFFSET $offset";

            $stmt = $this->dbh->prepare($query);

            if ($stmt->execute($arg)) {
                if ($stmt->rowCount() === 0) {
                    $result = [ 'success' => false, 'err' => 'Query is empty'];
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
                $result = [ 'success' => false, 'err' => $stmt->errorInfo()];
            }


        } catch (\PDOException $e) {
            $result = [ 'success' => false, 'err' => $e->getMessage()];
        }

        return $result;
    }

    public function getSeries($hash) {
        $result = [ 'success' => 1, 'result' => null ];
        try {
            $query = "select series_id from opencast_series_hash series
                        where short_code = :hash order by created_at desc limit 1"; // and workflow_id = :workflow_id
            $stmt = $this->dbh->prepare($query);
            $stmt->execute([':hash' => $hash]); // ':workflow_id' => $worfklow_details['oid']
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
