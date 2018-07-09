<?php

namespace App\Entity;

use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Validator\Constraints as Assert;
use App\Entity\Course;
use App\Entity\Department;
use App\Service\Utilities;

class Workflow
{
    private $dbh = null;

    private $oid;
    private $year;
    private $status;
    private $active;
    /**
     * @Assert\DateTime()
     */
    private $date_start;
    /**
     * @Assert\DateTime()
     */
    private $date_dept;
    /**
     * @Assert\DateTime()
     */
    private $date_course;
    /**
     * @Assert\DateTime()
     */
    private $date_schedule;
    

    public function __construct() {
        if (!$this->dbh) {
            $this->connectLocally();
        }

        try {
            $query = "select * from uct_workflow where active = 1 limit 1";
            $stmt = $this->dbh->prepare($query);
            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);

                $this->oid = $result[0]['id'];
                $this->year = $result[0]['year'];
                $this->status = $result[0]['status'];
                $this->date_start = new \DateTime($result[0]['date_start']);
                $this->date_dept = new \DateTime($result[0]['date_dept']);
                $this->date_course = new \DateTime($result[0]['date_course']);
                $this->date_schedule = new \DateTime($result[0]['date_schedule']);
                $this->active = ($result[0]['active'] ? true : false);
            } else {
                $this->status = $stmt->rowCount();
            }
        } catch (\PDOException $e) {
            throw new \Exception($e->getMessage());
        }
    }

    /**
     * Get active workflow (only one running at any one time
     */
    public function getWorkflow() {
        return [
            'year' => $this->year,
            'status' => $this->status,
            'date_start' => $this->date_start,
            'date_dept' => $this->date_dept,
            'date_course' => $this->date_course,
            'date_schedule' => $this->date_course,
            'active' => $this->active 
        ];
    }
  
    /**
     * 
     */
    public function run() {
        
        $now = new \DateTime();
        $result = [ 'success' => 1 ];

        //ENUM('init', 'start', 'run', 'dept', 'dept_mail', 'course', 'course_mail', 'done')
        switch($this->status) {
            case 'init':
                // So initialization of this workflow
                // (wait for start date)
                if ($now->diff($this->date_start)->format('%R') == '-') {
                    // > state: start
                    $this->setState('start');
                }
                break;
            case 'start':
                // Create the hashing for all the courses
                // > status : run
                $this->setState('run');
                break;
            case 'run':
                // (wait for department date)
                if ($now->diff($this->date_dept)->format('%R') == '-') {

                    // Create email entries for each departments head, so mail can be sent to each                   
                    $action = $this->createDepartmentMails();
                    if ($action === 1) {
                        // > state: dept
                        $this->setState('dept');
                    } else {
                        $result = [ 'success' => 0, 'err' => $action];
                    }
                }
                break;
            case 'dept':
                // (wait for course date)
                if ($now->diff($this->date_course)->format('%R') == '-') {

                    // Create email entries for each COURSE, so mail can be sent to each 
                    if ($this->createCourseMails()) {
                        // > state: course
                        $this->setState('course');
                    } else {
                        $result = [ 'success' => 0, 'err' => 'Error creating course mails'];
                    }
                }
                break;
            case 'course':
                // (wait for schedule date)
                if ($now->diff($this->date_schedule)->format('%R') == '-') {

                    // do scheduling

                    // > state: done
                    $this->setState('done');
                }
                break;
            case 'done':
                // All done
                // create new workflow that will run in the future
                // set this one inactive
                try {
                    $query = "UPDATE uct_workflow SET active = 0 WHERE active = 1";
                    $stmt = $this->dbh->prepare($query);
                    $stmt->execute();
                    $this->active = false;
                } catch (\PDOException $e) {
                    $result = [ 'success' => 0, 'err' => $e->getMessage()];
                }
                break;
            default:
                $result = [ 'success' => 0, 'err' => 'Error running workflow'];
                break;
        }

        $result['result'] = 'running: '. $this->status;
        return $result;
    }

    private function createDepartmentMails(){

        try {
            $insertQry = "INSERT INTO `uct_workflow_email` (`workflow_id`, `dept`, `mail_to`, `mail_cc`, `hash`, `name`) VALUES
                            (:workflow_id, :dept, :mail_to, :mail_cc, :hash, :name)";
            $mailStmt = $this->dbh->prepare($insertQry);

            // get list of departments
            $query = "SELECT * FROM uct_dept where `use_dept` = 1";
            $stmt = $this->dbh->prepare($query);
            $stmt->execute();
            
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {

                $dept = new Department($row['dept'], null, $this->year, true);
                $ar = [
                    ':workflow_id' => $this->oid, 
                    ':dept' => $row['dept'], 
                    ':mail_to' => $row['email'], 
                    ':mail_cc' => $row['alt_email'], 
                    ':hash' => $dept->getHash(),
                    ':name' => ( strlen($row['firstname']."".$row['lastname']) < 2 ? "Colleague" : $row['firstname'] ." ". $row['lastname']) ];

                //return $ar;

                if (!$mailStmt->execute($ar)) {
                    return $this->dbh->errorInfo();
                }
            }
        } catch (\PDOException $e) {
            return $e->getMessage();
        }

        return 1;
    }

    private function createCourseMails(){
        // TODO
        return 1;
    }    

    private function setState($status) {
        try {
            $query = "UPDATE uct_workflow SET status = :status WHERE active = 1";
            $stmt = $this->dbh->prepare($query);
            $stmt->execute([':status' => $status]);
            $this->status = $status;
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