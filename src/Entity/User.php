<?php

namespace App\Entity;

use App\Service\Utilities;

class User
{
    private $eid;
    private $ip;
    private $email;

    public function __construct($eid, $ip = null, $email = null) {
        if (!is_null($eid)) {
            $this->eid = $eid;
            $this->ip = $ip;
            $this->email = !!$email ? $email : (new Utilities())->getUserEmail($eid);
        }
        else if (!is_null($email)) {
            $searchResult = (new Utilities())->getCompleteUser($email);
            $this->eid = $searchResult['EID'];
            $this->email = $searchResult['EMAIL'];

        }
    }

    public function getDetails() {
        return [
            'username' => $this->eid,
            'ip' => $this->ip,
            'email' => $this->email
        ];
    }
}
