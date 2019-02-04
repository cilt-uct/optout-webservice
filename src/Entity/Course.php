<?php

namespace App\Entity;

use Symfony\Component\Dotenv\Dotenv;
use App\Service\OCRestService;
use App\Service\Utilities;

class Course extends AbstractOrganisationalEntity implements HashableInterface
{
    private $dbh = null;

    private $entityCode;
    private $parentEntityCode;
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

        $qry = "select A.course_code, A.term, A.dept, A.secret, A.start_date, A.end_date,
                if(A.acad_career = 'UGRD' and opencast_venues.campus_code in ('UPPER','MIDDLE'), 1, 0) as eligble,
                ifnull(C.convenor_name, A.convenor_name) as convenor_name,
                ifnull(C.convenor_eid, A.convenor_eid) as convenor_eid, D.is_optout, D.updated_at, D.updated_by,
                ifnull(C.convenor_email, (select E.email from vula_archive.SAKAI_USER_ARCHIVE E where C.convenor_eid = E.EID or (C.convenor_eid is null and A.convenor_eid = E.EID))) as email
                    from timetable.ps_courses A
                    left join timetable.course_updates C on A.course_code = C.course_code and A.term = C.year
                    left join timetable.course_optout D on A.course_code = D.course_code and A.term = D.year
                    left join timetable.sn_timetable_versioned `sn` on `sn`.course_code = A.course_code and `sn`.term = A.term
                    left join timetable.opencast_venues on `sn`.archibus_id = opencast_venues.archibus_id
                where A.active = 1 and A.course_code = :course and A.term = :year limit 1";
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
        $this->updatedAt = $result[0]['updated_at'];
        $this->updatedBy = $result[0]['updated_by'];
        $this->parentEntityCode = $result[0]['dept'];
        $this->eligble = $result[0]['eligble'];
    }

    public function getDetails() {
        $fields = ['courseCode', 'year', 'convenor', 'optoutStatus', 'updatedAt', 'updatedBy', 'eligble'];

        $details = ['hash' => $this->getHash()];
        foreach ($fields as $idx => $field) {
            $details[$field] = $this->{$field};
        }
        if ($details['optoutStatus']) $details['optoutStatus'] = (int) $details['optoutStatus'];
        if ($details['eligble']) $details['eligble'] = (int) $details['eligble'];
        return $details;
    }

    public function getHash() {
        $utils = new Utilities();
        return $utils->userVisibleHash($this);
    }

    public function getFullHash() {
        return $this->fullHash;
    }

    public function updateCourse($changes, $updatedBy, $workflow_id) {
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
                $this->updateConvenorField($field, $change, $updatedBy, $workflow_id);
            }
            $this->dbh->commit();
        } catch (\Exception $e) {
            $this->dbh->rollBack();
            throw new \Exception($e->getMessage());
        }
    }

    private function updateConvenorField($field, $change, $updatedBy, $workflow_id) {
        if (!isset($change['to']) || is_null($change['to']) || empty($change['to'])) {
            throw new \Exception('bad request');
        }
        $updateQry = "insert into course_updates (course_code, year, updated_by, convenor_$field, workflow_id)
                    (select ifnull(B.course_code, A.course_code), :year, :user, :to, :workflow_id
                        from ps_courses A left join course_updates B on A.course_code = B.course_code and A.term = B.year
                        where A.course_code = :code and A.term = :year and
                        (B.convenor_$field = :from or
                            (B.convenor_$field is null and
                                (A.convenor_$field = :from or A.convenor_$field is null)
                            )
                        )
                    )
                on duplicate key update convenor_$field = :to, updated_by = :user";

        try {
            $updateStmt = $this->dbh->prepare($updateQry);
            $bind = [
                ':code' => $this->courseCode,
                ':year' => $this->year,
                ':from' => $change['from'],
                ':to' => $change['to'],
                ':user' => $updatedBy,
                ':workflow_id' => $workflow_id
            ];
            $updateStmt->execute($bind);
            if ($updateStmt->rowCount() === 0) {
                throw new \Exception('conflict');
            }
        } catch (\PDOException $e) {
            throw new \Exception($e->getMessage());
        }
    }

    public function checkIsTimetabled() {
        $activitiesQry = "select activity_id from sn_timetable_versioned where course_code = :course_code and (class_section like 'LG%' or class_section like 'MG%') and tt_version = (select max(version) from timetable_versions)";
        try {
            $isTimetabled = false;
            $activityStmt = $this->dbh->prepare($activitiesQry);
            $activityStmt->execute([':course_code' => $this->courseCode]);
            if ($activityStmt->rowCount() === 0) {
                return false;
            }

            $result = $activityStmt->fetchAll(\PDO::FETCH_ASSOC);
            $ocService = new OCRestService();
            for ($i = 0, $n = sizeof($result); $i < $n; $i++) {
                try {
                    if ($ocService->isTimetabled($result[$i]['activity_id'], $this->courseCode, $this->year)) {
                        $isTimetabled = true;
                        break;
                    }
                }
                catch (\Exception $e) {
                }
            }
            if ($isTimetabled) {
                return true;
            }

            return $ocService->isCourseHasEvents($this->courseCode, $this->year);
        } catch(\PDOException $e) {
            var_dump("pdo error");
        }

        return false;
    }

    public function updateOptoutStatus($user, $data, $workflow_id) {
        if (!$user) {
            throw new \Exception("Authorisation required (invalid user)");
        }

        $updateQry = "replace into course_optout (course_code, dept, is_optout, updated_by, updated_at, year, workflow_id)
                      values (:courseCode, :dept, ifnull(:status,0), :user,  now(), :year, :workflow_id)";

        try {
            $updateStmt = $this->dbh->prepare($updateQry);
            $updateStmt->execute([
                ':courseCode' => $this->entityCode,
                ':dept' => $this->parentEntityCode,
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
}
