<?php

namespace App\Entity;

use Symfony\Component\Dotenv\Dotenv;
use App\Entity\Course;

class Department
{
    private $dbh = null;

    private $deptCode;
    private $hash;
    private $year;
    private $skipCheck;

    private $deptName;
    private $hod;
    private $courses;
    private $generatedHash;

    public function __construct($deptCode, $hash, $year = '', $skipCheck = false) {
        $this->deptCode = $deptCode;
        $this->hash = $hash;
        $this->year = !empty($year) ? $year : date('Y');
        $this->skipCheck = $skipCheck;

        $this->fetchDetails();
        $this->fetchCourses();
    }

    public function fetchDetails() {
        if (!$this->dbh) {
            $this->connectLocally();
        }

        $qry = "select A.*, B.secret from uct_dept A join dept_secrets B on A.dept = B.dept where A.dept = :dept and B.acadyear = :year order by B.acadyear desc limit 1";
        $stmt = $this->dbh->prepare($qry);
        $stmt->execute([':dept' => $this->deptCode, ':year' => $this->year]);
        if ($stmt->rowCount() === 0) {
            throw new \Exception("no such dept");
        }
        $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if (!$this->skipCheck && substr(hash('sha256', $this->deptCode . "." . $result[0]['secret']), 0, 6) !== $this->hash) {
            throw new \Exception("invalid hash");
        }
        $this->deptName = $result[0]['name'];
        $this->hod = implode(' ',
                       array_filter([$result[0]['firstname'], $result[0]['lastname']], function($val) {
                         return !is_null($val) && !empty($val);
                       })
                     );
        $this->generatedHash = substr(hash('sha256', $this->deptCode . "." . $result[0]['secret']), 0, 6);
   }

    public function fetchCourses() {
        if (!$this->dbh) {
            $this->connectLocally();
        }

        $qry = "select A.course_code from ps_courses A join course_optout B on A.course_code = B.course_code where A.dept = :dept and A.term = :year";
        $stmt = $this->dbh->prepare($qry);
        $stmt->execute([':dept' => $this->deptCode, ':year' => $this->year]);
        if ($stmt->rowCount() === 0) {
            throw new \Exception("no courses in dept");
        }
        $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $year = $this->year;
        $this->courses = array_map(function($course) use ($year) {
                           $courseInfo = new Course($course['course_code'], null, $year, true);
                           return $courseInfo->getDetails();
                         }, $result);
    }

    public function getDetails() {
        return [
            'dept' => $this->deptCode,
            'name' => $this->deptName,
            'hod' => $this->hod,
            'courses' => $this->courses
        ];
    }

    public function getHash() {
        return $this->generatedHash;
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
