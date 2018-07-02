<?php

namespace App\Entity;

use Symfony\Component\Dotenv\Dotenv;
use App\Service\Utilities;

class Course extends AbstractOrganisationalEntity implements HashableInterface
{
    private $dbh = null;

    private $entityCode;
    private $hash;
    private $year;
    private $skipHashCheck;

    public $courseCode;

    private $convenor;
    private $optoutStatus;
    private $ocSeries;
    private $vulaSiteId;
    private $isTimetabled;
    private $secret;
    private $fullHash;

    public function __construct($entityCode, $hash, $year = '', $skipHashCheck = false) {
        $this->entityCode = $this->courseCode = $entityCode;
        $this->hash = $hash;
        $this->year = !empty($year) ? $year : date('Y');
        $this->skipHashCheck = $skipHashCheck;

        parent::__construct($entityCode, $hash, $year, $skipHashCheck);
    }

    public function fetchDetails() {
        if (!$this->dbh) {
            $this->connectLocally();
        }

        $utils = new Utilities();

        $qry = "select A.course_code, A.acadyear, A.secret, B.start_date, B.end_date, ifnull(C.convenor_name, B.convenor_name) as convenor_name,
                ifnull(C.convenor_eid, B.convenor_eid) as convenor_eid, D.is_optout, D.optout_date, D.modified_by, E.email from course_secrets A
                    join ps_courses B on A.course_code = B.course_code and A.acadyear = B.term
                    left join course_updates C on A.course_code = C.course_code and A.acadyear = C.acadyear
                    left join course_optout D on A.course_code = D.course_code
                    left join vula_archive.SAKAI_USER_ARCHIVE E on C.convenor_eid = E.EID or (C.convenor_eid is null and B.convenor_eid = E.EID)
                where A.course_code = :course and A.acadyear = :year limit 1";
        $stmt = $this->dbh->prepare($qry);
        $stmt->execute([':course' => $this->entityCode, ':year' => $this->year]);
        if ($stmt->rowCount() === 0) {
            throw new \Exception("no such course");
        }
        $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $this->fullHash = hash('sha256', $this->entityCode . "." . $result[0]['secret']);
        if (!$this->skipHashCheck && $utils->userVisibleHash($this) !== $this->hash) {
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
        if ($details['optoutStatus']) $details['optoutStatus'] = (int) $details['optoutStatus'];
        return $details;
    }

    public function getHash() {
        $utils = new Utilities();
        return $utils->userVisibleHash($this);
    }

    public function getFullHash() {
        return $this->fullHash;
    }

    public function updateCourse($changes, $modifiedBy) {
        $this->dbh->beginTransaction();
        $allowedFields = [
            'convenorName' => 'name',
            'convenorEmail' => 'email',
            'convenorEid' => 'eid'
        ];
        try {
            foreach ($changes as $index => $change) {
                if (!in_array($change['field'], array_keys($allowedFields))) {
                    continue;
                }

                $field = $allowedFields[$change['field']];
                $this->updateConvenorField($field, $change, $modifiedBy);
            }
            $this->dbh->commit();
        } catch (\Exception $e) {
            $this->dbh->rollBack();
            throw new \Exception($e->getMessage());
        }
    }

    private function updateConvenorField($field, $change, $modifiedBy) {
        $updateQry = "insert into course_updates (course_code, acadyear, modified_by, convenor_$field)
                        (select ifnull(B.course_code, A.course_code), :year, :user, :to
                         from ps_courses A left join course_updates B on A.course_code = B.course_code
                         where A.course_code = :code and A.term = :year and
                           (B.convenor_$field = :from or (B.convenor_$field is null and
                               (A.convenor_$field = :from or A.convenor_$field is null)
                             )
                           )
                        )
                      on duplicate key update convenor_$field = :to, modified_by = :user";

        try {
            $updateStmt = $this->dbh->prepare($updateQry);
            $bind = [
                ':code' => $this->courseCode,
                ':year' => $this->year,
                ':from' => $change['from'],
                ':to' => $change['to'],
                ':user' => $modifiedBy
            ];
            $updateStmt->execute($bind);
            if ($updateStmt->rowCount() === 0) {
                throw new \Exception('conflict');
            }
        } catch (\PDOException $e) {
            throw new \Exception($e->getMessage());
        }
    }

    public function updateOptoutStatus($user, $data) {
        if (!$user) {
            throw new \Exception("Authorisation required");
        }

        $updateQry = "replace into course_optout (course_code, is_optout, modified_by, optout_date, acadyear)
                      values (:courseCode, :status, :user,  now(), :acadyear)";

        try {
            $updateStmt = $this->dbh->prepare($updateQry);
            $updateStmt->execute([
                ':courseCode' => $this->entityCode,
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
}
