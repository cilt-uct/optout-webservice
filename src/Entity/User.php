<?php

namespace App\Entity;

use App\Service\Utilities;

class User
{
    private $eid;
    private $ip;
    private $email;

    public function __construct($eid, $ip = null, $email = null) {
        $this->eid = $eid;
        $this->ip = $ip;
        $this->email = !!$email ? $email : (new Utilities())->getUserEmail($eid);
    }

    public function getDetails() {
        return [
            'username' => $this->eid,
            'ip' => $this->ip,
            'email' => $this->email
        ];
    }
}
