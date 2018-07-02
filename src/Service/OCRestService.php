<?php

namespace App\Service;

use Symfony\Component\Dotenv\Dotenv;

class OCRestService
{

    private $ocHost;
    private $ocUser;
    private $ocPass;

    public function __construct() {
        //Get environment variables
        $dotenv = new Dotenv();
        $dotenv->load('.env');

        //Get credentials
        $this->ocHost = getenv('OC_HOST');
        $this->ocUser = getenv('OC_USER');
        $this->ocPass = getenv('OC_PASS');
    }

    private function getOCSeries($courseCode, $year) {
        $url = $this->ocHost . "/api/series/?filter=textFilter:$courseCode&limit=10";
        $headers = ['X-Requested-Auth: Digest'];

        $series = json_decode($this->getRequest($url, $headers), true);
        if (!is_array($series) || !sizeof($series)) {
            return [];
        }

        $series = array_filter($series, function($s) use ($year) {
                      return strpos($s['title'], $year) > -1;
                  });

        return $series;
    }

    public function hasOCSeries($courseCode, $year) {
        try {
            $checkSeries = $this->getOCSeries($courseCode, $year);
            return sizeof($checkSeries) > 0;
        } catch (\Exception $e) {
            var_dump($e);
            throw new \Exception($e->getMessage());
        }
    }

    private function getRequest($url, $headers, $opts = [], $body = []) {
        $curl = curl_init();

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_URL , $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER , $headers);
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
        curl_setopt($curl, CURLOPT_USERPWD, $this->ocUser . ':' . $this->ocPass);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_FAILONERROR, false);
        curl_setopt($curl, CURLINFO_HEADER_OUT, true);
        curl_setopt($curl, CURLOPT_VERBOSE, true);

        $response = curl_exec($curl);

        $info = curl_getinfo($curl);

        // Check for errors
        if (curl_errno($curl)) {
            throw new \Exception(curl_error($curl));
        }

        // Check for errors
        if ($info['http_code'] >= 400) {
            $response = json_decode($response, true);

            $message = json_encode($info);

            //throw new \Exception($message);
        }

        // Close request and clear some resources
        curl_close($curl);

        return $response;
    }

}
