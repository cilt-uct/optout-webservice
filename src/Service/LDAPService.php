<?php

namespace App\Service;

class LDAPService
{

    //TODO: add ldap variables to .env, e.g. o=uct as base dn
    private $ldapConn;
    private $returnFields = [
       'alternateemail'
      ,'preferredname'
      ,'mobile'
      ,'cn'
      ,'sn'
      ,'dn'
    ];

    public function __construct($host = '', $user = '', $pass = '') {
        if (empty($host)) {
            $this->ldapConn = \ldap_connect('ldaps://ldap.uct.ac.za', 636);
        }
        else {
            $this->ldapConn = \ldap_connect($host, 636);
        }
    }

    public function search($filter) {
        $ldapSearch = \ldap_search($this->ldapConn, 'o=uct', $filter);
        if ($ldapSearch != false) {
            $ldapSearchArray = \ldap_get_entries($this->ldapConn, $ldapSearch);
            if (!$ldapSearchArray['count']) {
                throw new \Exception("no such user");
            }
            $searchResults = [];
            foreach ($ldapSearchArray as $key => $val) {
              if (is_numeric($key)) {
                $result = [];
                foreach ($this->returnFields as $idx => $field) {
                  $result[$field] = isset($ldapSearchArray[$key][$field]) && is_array($ldapSearchArray[$key][$field]) ? $ldapSearchArray[$key][$field]['0'] : (isset($ldapSearchArray[$key][$field]) ? $ldapSearchArray[$key][$field] : null);
                }
                $searchResults[] = $result;
              }
            }

            $result = [];
            if (count($searchResults) > 0) {
                foreach($searchResults as $user) {
                    if (isset($user['mobile'])) {
                        unset($user['mobile']);
                    }
                    array_push($result, $user);
                }
            }

            return $result;
        }
        else {
            throw new \Exception("cannot connect to ldap server");
        }
    }

    public function match($search) {
        if (filter_var($search, FILTER_VALIDATE_EMAIL)) {
            $uctEmailSuffixPosition = strpos($search, '@uct.ac.za');
            if ($uctEmailSuffixPosition > -1) {
                $nameArr = explode('.', substr($search, 0, $uctEmailSuffixPosition));
                $firstname = $nameArr[0];
                $surname = implode(' ', array_splice($nameArr, 1));
                $filter = "(&(preferredName=$firstname)(sn=$surname))";
            }
            else {
                $filter = "(alternateEmail=$search)";
            }
        }
        else {
            $filter = "(cn=$search)";
        }

        return $this->search($filter);
    }

    public function authenticate($username, $password) {
        if (!is_numeric($username)) {
          throw new \Exception('invalid id');
        }
        try {
            $searchUser = ($this->match($username))[0];
            //Match Exact?! shouldn't infer from $username. Needs to be staff number
            if (@\ldap_bind($this->ldapConn, $searchUser['dn'], $password)) {
                return true;
            }
            return false;
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
            switch ($e->getMessage()) {
                case 'no such user':
                    throw new \Exception($e->getMessage());
            }
        }
    }
}
