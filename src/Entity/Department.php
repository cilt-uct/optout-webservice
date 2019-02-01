<?php

namespace App\Entity;

use Symfony\Component\Dotenv\Dotenv;
use App\Entity\Course;
use App\Service\Utilities;

class Department extends AbstractOrganisationalEntity implements HashableInterface
{
    private $dbh = null;

    private $entityCode;
    private $hash;
    private $year;
    private $skipHashCheck;

    private $deptName;
    private $hod;
    private $hodMail;
    private $hodEID;
    public $isOptOut;
    public $courses;
    private $fullHash;

    public function __construct($entityCode, $hash, $year = '', $skipHashCheck = false, $skipCourses = true) {
        $this->entityCode = $entityCode;
        $this->hash = $hash;
        $this->year = !empty($year) ? $year : date('Y');
        $this->skipHashCheck = $skipHashCheck;

        parent::__construct($entityCode, $hash, $year, $skipHashCheck);

        try {
            $this->fetchDetails();
            if (!$skipCourses) {
                $this->fetchCourses();
            }
        } catch (\Exception $e) {
            $this->courses = [];
        }
    }

    public function fetchDetails() {
        if (!$this->dbh) {
            $this->connectLocally();
        }

        $qry = "select A.*, '002200' as hod_eid, B.year, B.is_optout from uct_dept A left join dept_optout B on A.dept = B.dept where A.dept = :dept and B.year = :year order by year desc limit 1";
        $stmt = $this->dbh->prepare($qry);
        $stmt->execute([':dept' => $this->entityCode, ':year' => $this->year]);
        if ($stmt->rowCount() === 0) {
            throw new \Exception("no such dept [". $this->entityCode ."][". $this->year ."]");
        }
        $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $this->fullHash = hash('sha256', $this->entityCode . "." . $result[0]['secret']);
        if (!$this->skipHashCheck && substr(hash('sha256', $this->entityCode . "." . $result[0]['secret']), 0, 6) !== $this->hash) {
            throw new \Exception("invalid hash");
        }
        $this->deptName = $result[0]['name'];
        $this->hod = implode(' ',
                       array_filter([$result[0]['firstname'], $result[0]['lastname']], function($val) {
                         return !is_null($val) && !empty($val);
                       })
                     );
        $this->hodMail = $result[0]['email'];
        $this->hodEID = $result[0]['hod_eid'];
        $this->isOptOut = $result[0]['is_optout'] === '1' ? true : false;
    }

    public function fetchCourses() {
        if (!$this->dbh) {
            $this->connectLocally();
        }

        $qry = "select A.course_code from ps_courses A join course_optout B on A.course_code = B.course_code where A.active = 1 and A.dept = :dept and A.term = :year and A.acad_career = 'UGRD'";
        $stmt = $this->dbh->prepare($qry);
        $stmt->execute([':dept' => $this->entityCode, ':year' => $this->year]);
        if ($stmt->rowCount() === 0) {
            throw new \Exception("no courses in dept");
        }
        $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $year = $this->year;
        $this->courses = array_map(function($course) use ($year) {
                             return new Course($course['course_code'], null, $year, true);
                         }, $result);
    }

    public function getDetails($skipCourses = false) {
        $result = [
            'dept' => $this->entityCode,
            'name' => $this->deptName,
            'hod' => $this->hod,
            'mail' => $this->hodMail,
            'eid' => $this->hodEID,
            'is_optout' => $this->isOptOut,
            'hash' => $this->getHash(),
            'courses' => []
        ];

        if ((!$skipCourses) && (gettype($this->courses) == 'array')) {
            $result['courses'] = array_map(function($course) { return $course->getDetails(); }, $this->courses);
        }

        return $result;
    }

    public function getHash() {
        $utils = new Utilities();
        return $utils->userVisibleHash($this);
    }

    public function getFullHash() {
        return $this->fullHash;
    }

    public function updateDepartment($changes, $updatedBy) {
        /*
        $changes = [{
            "field": name (convenorName / convenorEmail),
            "from": old value,
            "to": new value
          }]
        */
        $allowedFields = [
            'hodFirstname' => 'firstname',
            'hodLastname' => 'lastname',
            'hodMail' => 'email',
            'altMail' => 'alt_email',
            'active' => 'use_dept'
        ];
        try {
            $this->dbh->beginTransaction();

            foreach ($changes as $index => $change) {
                if (!in_array($change['field'], array_keys($allowedFields))) {
                    continue;
                }

                $field = $allowedFields[$change['field']];
                $this->updateField($field, $change, $updatedBy);
            }

            $this->dbh->commit();
        } catch (\Exception $e) {
            $this->dbh->rollBack();
            throw new \Exception($e->getMessage());
        }
    }

    private function updateField($field, $change, $updatedBy) {
        if (!isset($change['to']) || is_null($change['to'])) {
            if (empty($change['to']) && ($field <> 'active')) {
                throw new \Exception('bad request');
            }
        }
        $updateQry = "update uct_dept A
                        set $field = :to, updated_by = :user
                        where A.dept = :dept and (A.$field = :from or A.$field is null)";

        try {
            $updateStmt = $this->dbh->prepare($updateQry);
            $bind = [
                ':dept' => $this->entityCode,
                ':from' => $change['from'],
                ':to' => $change['to'],
                ':user' => $updatedBy
            ];
            $updateStmt->execute($bind);
            if ($updateStmt->rowCount() === 0) {
                throw new \Exception('conflict: '. $updateStmt->debugDumpParams());
            }
        } catch (\PDOException $e) {
            throw new \Exception($e->getMessage());
        }
    }

    public function updateOptoutStatus($user, $data, $workflow_id) {
        if (!$user) {
            throw new \Exception("Authorisation required (invalid user)");
        }

        $updateQry = "replace into dept_optout (dept, is_optout, updated_by, updated_at, year, workflow_id)
                      values (:dept, ifnull(:status,0), :user,  now(), :year, :workflow_id)";

        try {
            $updateStmt = $this->dbh->prepare($updateQry);
            $updateStmt->execute([
                ':dept' => $this->entityCode,
                ':status' => $data['status'],
                ':user' => $user,
                ':year' => $this->year,
                ':workflow_id' => $workflow_id
            ]);

            $date = new \DateTime('now');
            $date->setTimezone(new \DateTimeZone('Africa/Johannesburg'));
            return ['success' => $updateStmt->rowCount() > 0, 'user' => $user, 'date' => $date->format('Y-m-d H:i:s')];
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

    public function __destruct() {
    }
}
