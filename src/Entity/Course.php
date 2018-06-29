<?php

namespace App\Entity;

use Symfony\Component\Dotenv\Dotenv;

class Course
{
    private $dbh = null;

    private $courseCode;
    private $hash;
    private $year;
    private $skipHashCheck;

    private $convenor;
    private $optoutStatus;
    private $ocSeries;
    private $vulaSiteId;
    private $isTimetabled;
    private $secret;

    public function __construct($courseCode, $hash, $year = '', $skipHashCheck = false) {
        $this->courseCode = $courseCode;
        $this->hash = $hash;
        $this->year = !empty($year) ? $year : date('Y');
        $this->skipHashCheck = $skipHashCheck;

        $this->fetchDetails();
    }

    public function fetchDetails() {
        if (!$this->dbh) {
            $this->connectLocally();
        }

        $qry = "select A.course_code, A.acadyear, A.secret, B.start_date, B.end_date, B.convenor_name, B.convenor_eid,
                C.is_optout, C.optout_date, C.modified_by, D.email from course_secrets A
                    join ps_courses B on A.course_code = B.course_code and A.acadyear = B.term
                    left join course_optout C on A.course_code = C.course_code
                    left join vula_archive.SAKAI_USER_ARCHIVE D on B.convenor_eid = D.EID
                where A.course_code = :course and A.acadyear = :year limit 1";
        $stmt = $this->dbh->prepare($qry);
        $stmt->execute([':course' => $this->courseCode, ':year' => $this->year]);
        if ($stmt->rowCount() === 0) {
            throw new \Exception("no such course");
        }
        $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if (!$this->skipHashCheck && substr(hash('sha256', $this->deptCode . "." . $result[0]['secret']), 0, 6) !== $this->hash) {
            throw new \Exception("invalid hash");
        }

        $this->convenor = [
            'eid' => $result[0]['convenor_eid'],
            'name' => $result[0]['convenor_name'],
            'email' => $result[0]['email']
        ];
        $this->optoutStatus = $result[0]['is_optout'];
        $this->optoutDate = $result[0]['optout_date'];
        $this->modifiedBy = $result[0]['modified_by'];
    }

    public function getDetails() {
        $fields = ['courseCode', 'year', 'convenor', 'optoutStatus', 'optoutDate', 'modifiedBy'];

        $details = [];
        foreach ($fields as $idx => $field) {
            $details[$field] = $this->{$field};
        }

        return $details;
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
