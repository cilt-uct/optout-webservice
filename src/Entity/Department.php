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
    public $courses;
    private $fullHash;

    public function __construct($entityCode, $hash, $year = '', $skipHashCheck = false) {
        $this->entityCode = $entityCode;
        $this->hash = $hash;
        $this->year = !empty($year) ? $year : date('Y');
        $this->skipHashCheck = $skipHashCheck;

        parent::__construct($entityCode, $hash, $year, $skipHashCheck);

        $this->fetchCourses();
    }

    public function fetchDetails() {
        if (!$this->dbh) {
            $this->connectLocally();
        }

        $qry = "select A.*, B.secret from uct_dept A join dept_secrets B on A.dept = B.dept where A.dept = :dept and B.acadyear = :year order by B.acadyear desc limit 1";
        $stmt = $this->dbh->prepare($qry);
        $stmt->execute([':dept' => $this->entityCode, ':year' => $this->year]);
        if ($stmt->rowCount() === 0) {
            throw new \Exception("no such dept");
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
    }

    public function fetchCourses() {
        if (!$this->dbh) {
            $this->connectLocally();
        }

        $qry = "select A.course_code from ps_courses A join course_optout B on A.course_code = B.course_code where A.dept = :dept and A.term = :year";
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

    public function getDetails() {
        return [
            'dept' => $this->entityCode,
            'name' => $this->deptName,
            'hod' => $this->hod,
            'courses' => array_map(function($course) {
                             return $course->getDetails();
                         }, $this->courses)
        ];
    }

    public function getHash() {
        $utils = new Utilities();
        return $utils->userVisibleHash($this);
    }

    public function getFullHash() {
        return $this->fullHash;
    }

    public function updateOptoutStatus($user, $data) {
        if (!$user) {
            throw new \Exception("Authorisation required (invalid user)");
        }

        $updateQry = "replace into dept_optout (dept, is_optout, modified_by, optout_date, acadyear)
                      values (:dept, ifnull(:status,0), :user,  now(), :acadyear)";

        try {
            $updateStmt = $this->dbh->prepare($updateQry);
            $updateStmt->execute([
                ':dept' => $this->entityCode,
                ':status' => $data['status'],
                ':user' => $user,
                ':acadyear' => $this->year
            ]);
            return $updateStmt->rowCount();
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
