<?php

namespace App\Service;

use Symfony\Component\Dotenv\Dotenv;
use App\Service\SakaiWebService;
use App\Service\OCRestService;
use App\Entity\HashableInterface;

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
            $qry = "select distinct sn_timetable_versioned.course_code, ps_courses.term, ps_courses.dept FROM sn_timetable_versioned
                      inner join opencast_venues on sn_timetable_versioned.venue = opencast_venues.sn_venue
                      inner join ps_courses on sn_timetable_versioned.course_code = ps_courses.course_code and sn_timetable_versioned.term = ps_courses.term
                    WHERE sn_timetable_versioned.term=:year and instruction_type='Lecture'
                    and tt_version in (select max(version) from timetable_versions)
                    and ((date_add(curdate(), interval 2 week) > :this_year_half and ps_courses.end_date > :this_year_half) or
                         (date_add(curdate(), interval 2 week) < :this_year_half and ps_courses.end_date < :this_year_half)
                        )
                    and campus_code in ('UPPER','MIDDLE')
                    and sn_timetable_versioned.course_code not in (select course_code from course_optout where acadyear = :year)
                    order by course_code";

            $yearHalf = date('Y-m-d', strtotime(date("Y") . "-07-01"));
            $stmt = $this->dbh->prepare($qry);
            $stmt->execute([
                ':year' => date('Y'),
                ':this_year_half' => $yearHalf
            ]);

            $optoutQry = "insert into course_optout (course_code, acadyear, dept) values (:course, :year, :dept) on duplicate key update course_code = :course";
            $secretsQry = "insert into course_secrets (course_code, acadyear, secret) values (:course, :year, :secret) on duplicate key update course_code = :course";
            $optoutStmt = $this->dbh->prepare($optoutQry);
            $secretsStmt = $this->dbh->prepare($secretsQry);

            $updateResults = [
                'coursesFound' => $stmt->rowCount(),
                'coursesUpdated' => 0
            ];

            if ($stmt->rowCount() > 0) {
                while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    $optoutStmt->execute([
                        ':course' => $row['course_code'],
                        ':year' => $row['term'],
                        ':dept' => $row['dept']
                    ]);
                    $secretsStmt->execute([
                        ':course' => $row['course_code'],
                        ':year' => $row['term'],
                        ':secret' => mt_rand() . '.' . bin2hex(random_bytes(16))
                    ]);
                    $updateResults['coursesUpdated'] += $optoutStmt->rowCount();
                }
                $this->updateVulaSites();
                $this->updateOCSeries();
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
                throw new Exception("no such user");
            }

            $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            return $result[0]['EMAIL'];
        } catch (\PDOException $e) {
            throw new Exception($e->getMessage());
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
