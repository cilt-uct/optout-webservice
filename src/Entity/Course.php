<?php

namespace App\Entity;

use Symfony\Component\Dotenv\Dotenv;

class Course {

    private $dbh;
    private $vula;
    private $oc;

    private $course_code;
    private $convenor;
    private $status;
    private $ocSeries;
    private $vulaSiteId;
    private $isTimetabled;
    private $secret;

    public function __construct($id, $dbh, $vula) {
        
    }
}
