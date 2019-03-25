<?php

namespace App\Service;

use Symfony\Component\Dotenv\Dotenv;
use App\Service\SakaiWebService;
use App\Service\OCRestService;
use App\Entity\HashableInterface;
use App\Entity\Course;
use App\Entity\Department;
use App\Entity\Workflow;

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

            $qry = "select distinct `sn`.course_code, `ps`.term, `ps`.dept
                    FROM sn_timetable_versioned `sn`
                        inner join opencast_venues  `venue`  on `sn`.archibus_id =  `venue`.archibus_id
                        inner join ps_courses `ps` on `sn`.course_code = `ps`.course_code and `sn`.term = `ps`.term
                    WHERE
                        `sn`.term=2019
                        and `ps`.active = 1
                        and `ps`.acad_career = 'UGRD'
                        and `venue`.campus_code in ('UPPER','MIDDLE')
                        and `sn`.instruction_type='Lecture'
                        and `sn`.tt_version in (select max(version) from timetable_versions)
                        and ((date_add(curdate(), interval 2 week) > :this_year_half and `ps`.start_date > :this_year_half) or
                                (date_add(curdate(), interval 2 week) < :this_year_half and `ps`.start_date < :this_year_half))
                        and `sn`.course_code not in (select course_code from course_optout where year = :year)
                        order by `sn`.course_code";

            $yearHalf = date('Y-m-d', strtotime(date("Y") . "-07-01"));
            $stmt = $this->dbh->prepare($qry);
            $stmt->execute([
                ':year' => date('Y'),
                ':this_year_half' => $yearHalf
            ]);

            $optoutQry = "insert into course_optout (course_code, year, dept, workflow_id) values (:course, :year, :dept, :workflow_id) on duplicate key update course_code = :course, dept = :dept, workflow_id = :workflow_id";
            $optoutStmt = $this->dbh->prepare($optoutQry);

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
                        where hash = :hash and workflow_id = :workflow_id order by created_at desc limit 1";
            $stmt = $this->dbh->prepare($query);
            $stmt->execute([':hash' => $hash, ':workflow_id' => $worfklow_details['oid']]);
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
                            and  `venue` .campus_code in ('UPPER','MIDDLE')
                            and `sn`.instruction_type='Lecture'
                            and `sn`.tt_version in (select max(version) from timetable_versions)
                            and (date_add(curdate(), interval 2 week) < :this_year_half and `ps`.start_date < :this_year_half)) as eligble_courses_S1,
                    (SELECT count(distinct(`ps`.course_code))
                        FROM timetable.ps_courses `ps`
                            join timetable.course_optout `out` on `ps`.course_code = `out`.course_code and `ps`.term = `out`.year
                            left join timetable.sn_timetable_versioned `sn` on `sn`.course_code = `ps`.course_code and `sn`.term = `ps`.term
                            left join timetable.opencast_venues `venue` on `sn`.archibus_id =  `venue` .archibus_id
                        WHERE
                            `ps`.dept = A.dept and `ps`.term = B.year
                            and `ps`.active = 1
                            and `ps`.acad_career = 'UGRD'
                            and  `venue` .campus_code in ('UPPER','MIDDLE')
                            and `sn`.instruction_type='Lecture'
                            and `sn`.tt_version in (select max(version) from timetable_versions)
                            and (date_add(curdate(), interval 2 week) > :this_year_half and `ps`.start_date > :this_year_half)) as eligble_courses_S2,
                (SELECT count(*) FROM timetable.uct_workflow_email mail where mail.dept=A.dept and mail.workflow_id=:workflow_id and mail.state = 0 and course is not null) as mail_unsent,
                (SELECT count(*) FROM timetable.uct_workflow_email mail where mail.dept=A.dept and mail.workflow_id=:workflow_id and mail.state = 1 and course is not null) as mail_sent_note,
                (SELECT count(*) FROM timetable.uct_workflow_email mail where mail.dept=A.dept and mail.workflow_id=:workflow_id and mail.state = 1 and course is not null) as mail_sent_confirm,
                (SELECT count(*) FROM timetable.uct_workflow_email mail where mail.dept=A.dept and mail.workflow_id=:workflow_id and mail.state = 2 and course is not null) as mail_err
                from timetable.uct_dept A left join timetable.dept_optout B on A.dept = B.dept
                where B.year = :year";

            $yearHalf = date('Y-m-d', strtotime(date("Y") . "-07-01"));
            $stmt = $this->dbh->prepare($query);

            if ($stmt->execute([':year' => $worfklow_details['year'], ':this_year_half' => $yearHalf, ':workflow_id' => $worfklow_details['oid']])) {
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
