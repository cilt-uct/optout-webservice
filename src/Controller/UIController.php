<?php
// src/Controller/UIController.php
namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use App\Entity\Course;
use App\Entity\Department;
use App\Entity\Workflow;
use App\Service\LDAPService;
use App\Service\OCRestService;
use App\Service\SakaiWebService;
use App\Service\Utilities;

class UIController extends Controller
{
    /**
     * View the page according to the hash it receives
     *
     * @Route("/view/{hash}")
     */
    public function viewFromHash($hash, Request $request)
    {
        $authenticated = ['a' => false, 'z' => '0'];

        // testing
        if ($hash == 'zzz000') {
            $hash = 'b6ef9b';
        }

        $now = new \DateTime();
        $utils = new Utilities();
        $data = $utils->getMail($hash);

        switch ($request->getMethod()) {
            case 'POST':
                $ldap = new LDAPService();
                $user = $request->request->get('eid');
                $password = $request->request->get('pw');

                try {
                    if ($ldap->authenticate($user, $password)) {
                        $details = $ldap->match($user);
                        $session = $request->hasSession() ? $request->getSession() : new Session();
                        $session->set('username', $details[0]['cn']);
                        $authenticated['a'] = true;
                    } else {
                        $authenticated['z'] = 'Invalid username/password combination';
                    }
                } catch (\Exception $e) {
                    switch ($e->getMessage()) {
                        case 'no such user':
                            $authenticated['z'] = 'No such user';
                        break;
                        case 'invalid id':
                            $authenticated['z'] = 'Please use your official UCT staff number';
                        break;
                    }
                }
            break;
            default:
                $session = $request->hasSession() ? $request->getSession() : new Session();
                $authenticated['a'] = $session->get('username') ? true : false;
            break;
        }
        //return new Response(json_encode($data), 201);

        if (!$data['success']) {
            return $this->render('error.html.twig', $data);
        } else {
            $data = $data['result'][0];
            $data['hash'] = $hash;
            $data['out_link'] = 'https://srvslscet001.uct.ac.za/optout/out/'. $hash;
            $data['authenticated'] = $authenticated;
        }

        if ($data['course'] === null ) {
            $dept = new Department($data['dept'], $hash, $data['year'], false, false, true);
            $data['details'] = $dept->getDetails();
            $data['readonly'] = 1; //($now->diff(new \DateTime($data['date_course']))->format('%R') == '-');
            $data['readonly_s1'] = 1; //($now->diff(new \DateTime($data['date_course']))->format('%R') == '-');
            $data['readonly_s2'] = 0; //($now->diff(new \DateTime($data['date_course']))->format('%R') == '-');

            if (count($data['details']['courses']) == 0) {
            //     return $this->viewOptOut($hash, $request);
            } else {
                $semester_vals = array_column($data['details']['courses'], 'semester'); // take all 'semester' values
                $data['counts'] = array_count_values($semester_vals);
            }

            // return new Response(json_encode($data), 201);
            return $this->render('department.html.twig', $data);
        } else {
            $vula = new SakaiWebService();
            $ocService = new OCRestService();

            $course = new Course($data['course'], $hash, $data['year'], false, false); // last could be set to true

            $data['details'] = $course->getDetails();
            $data['readonly'] = ($now->diff(new \DateTime($data['date_schedule']))->format('%R') == '-');
            $data['hasVulaSite'] = $vula->hasProviderId($data['course'], $data['year']);
            $data['hasOCSeries'] = $ocService->hasOCSeries($data['course'], $data['year']);
            $data['isTimetabled'] = $data['hasOCSeries'] ? $course->checkIsTimetabledInOC() : false;
            $data['email_case'] = $data['case'];
            $data['email_type'] = $data['type'];

            // retrieve timetable information
            $json = file_get_contents('https://srvslscet001.uct.ac.za/timetable/?historic=1&course='. $data['course'] .','. $data['year']);
            $data['timetable'] = json_decode($json);

            // return new Response(json_encode($data), 201);
            return $this->render('course.html.twig', $data);
        }
    }

    /**
     * @Route("/out/{hash}")
     */
    public function viewOptOut($hash, Request $request)
    {
        $now = new \DateTime();
        $utils = new Utilities();
        $data = $utils->getMail($hash);
        $authenticated = ['a' => false, 'z' => '0'];
        $confirmed = false;

        // get department
        try {
            $dept = new Department($data['result'][0]['dept'], $hash, $data['result'][0]['year']);
            $confirmed = $dept->isOptOut;
        } catch (\Exception $e) {
            $hash = null;
        }

        switch ($request->getMethod()) {
            case 'POST':
                $type = $request->request->get('type');

                switch($type) {
                    case 'login':
                        $ldap = new LDAPService();
                        $user = $request->request->get('eid');
                        $password = $request->request->get('pw');

                        try {
                            if ($ldap->authenticate($user, $password)) {
                                $details = $ldap->match($user);
                                $session = $request->hasSession() ? $request->getSession() : new Session();
                                $session->set('username', $details[0]['cn']);
                                $authenticated['a'] = true;
                            } else {
                                $authenticated['z'] = 'Invalid username/password combination';
                            }
                        } catch (\Exception $e) {
                            switch ($e->getMessage()) {
                                case 'no such user':
                                    $authenticated['z'] = 'No such user';
                                break;
                                case 'invalid id':
                                    $authenticated['z'] = 'Please use your official UCT staff number';
                                break;
                            }
                        }
                    break;
                    case 'ask':
                        if ($dept) {

                            $session = $request->hasSession() ? $request->getSession() : new Session();
                            $authenticated['a'] = $session->get('username') ? true : false;
                            $workflow = (new Workflow)->getWorkflow();

                            $updated = $dept->updateOptoutStatus($session->get('username'), ['status' => $request->request->get('optout_confirm') ], $workflow['oid']);
                            $confirmed = $updated['success'];
                        }
                    break;
                }
            break;
            default:
                $session = $request->hasSession() ? $request->getSession() : new Session();
                $authenticated['a'] = $session->get('username') ? true : false;
            break;
        }


        if (!$data['success'] || $hash == null) {
            return $this->render('error.html.twig', $data);
        } else {
            $data = $data['result'][0];
            $data['hash'] = $hash;
            $data['out_link'] = 'https://srvslscet001.uct.ac.za/optout/out/'. $hash;
            $data['authenticated'] = json_encode($authenticated);
            $data['confirmed'] = json_encode($dept->getDetails());
            $data['details'] = $dept->getDetails();
            $data['readonly'] = ($now->diff(new \DateTime($data['date_course']))->format('%R') == '-');

            if (!$data['readonly']) {
                if ($authenticated['a']) {
                    // authenticated - show confirm page
                    if ($confirmed) {
                        // show confirmed page
                        return $this->render('department_out_3_confirmed.html.twig', $data);
                    } else {
                        // show confirm choice
                        return $this->render('department_out_2_ask.html.twig', $data);
                    }
                } else {
                    return $this->render('department_out_1_login.html.twig', $data);
                }
            } else {
                return $this->viewFromHash($hash, $request);
            }
        }
    }

    /**
     * @Route("/mail/{hash}")
     */
    public function getMail($hash, Request $request)
    {
        $utils = new Utilities();

        // testing
        if ($hash == 'zzz000') {
            $hash = 'b6ef9b';
        }

        $data = $utils->getMail($hash);
        //return new Response(json_encode($data), 201);

        if ($data['success']) {
            $data = $data['result'][0];

            if ($data['course'] === null ) {
                $dept = new Department($data['dept'], $hash, $data['year'], false);
                $details = $dept->getDetails();

                return $this->render('department_mail.html.twig',
                    array(  'dept' => $data['dept'],
                            'dept_name' => $details['name'],
                            'name' => $data['name'],
                            'date' => $data['date_course'],
                            'out_link' => 'https://srvslscet001.uct.ac.za/optout/out/'. $hash,
                            'view_link' => 'https://srvslscet001.uct.ac.za/optout/view/'. $hash));
            } else {
                $course = new Course($data['course'], $hash, $data['year'], false);
                $details = $course->getDetails();

                $vula = new SakaiWebService();
                $site_list = $vula->getSiteByProviderId($data['course'], $data['year']);
                $site = '';
                if (count($site_list) > 0) {
                    $site = $site_list[0]['SITE_ID'];
                }

                $o = array( 'dept' => $data['dept'],
                            'course' => $data['course'],
                            'name' => $data['name'],
                            'site_list' => $site_list,
                            'site' => $site,
                            'date' => $data['date_schedule'],
                            'out_link' => 'https://srvslscet001.uct.ac.za/optout/out/'. $hash,
                            'view_link' => 'https://srvslscet001.uct.ac.za/optout/view/'. $hash);

                if ($data['type'] == 'confirm') {
                    switch($data['case']) {
                        case '1':
                        case '2':
                        case '3':
                        case '4':
                        case '5':
                        case '6':
                            return $this->render('course_mail_case_'. $data['case'] .'.html.twig', $o);
                            break;
                        default:
                            return $this->render('course_mail.html.twig', $o);
                            break;
                    }
                } else {
                    return $this->render('course_mail.html.twig', $o);
                }
            }
        } else {
            return new Response("ERROR_MAIL_HASH", 500);
        }
    }

    /**
     * @Route("/subject/{hash}")
     */
    public function getMailSubject($hash, Request $request)
    {
        $utils = new Utilities();

        // testing
        if ($hash == 'zzz000') {
            $hash = 'b6ef9b';
        }

        $data = $utils->getMail($hash);

        //return new Response(json_encode($data), 201);

        if ($data['success']) {
            $data = $data['result'][0];

            if ($data['course'] === null ) {
                $dept = new Department($data['dept'], $hash, $data['year'], false);
                $details = $dept->getDetails();

                return new Response("Automated Setup of Lecture Recording: Department Opt-Out process", 201);
            } else {
                $course = new Course($data['course'], $hash, $data['year'], false);
                $details = $course->getDetails();

                $str = $data['course'] ." course: Automated Setup or Opt-out of Lecture Recording" .
                        ($data['type'] == 'confirm' ? ' [Completed]' : '');

                return new Response($str, 201);
            }
        } else {
            return new Response("ERROR_MAIL_HASH", 500);
        }
    }

    /**
     * Main page
     *
     * @Route("/", name="Main")
     */
    public function defaultMain(Request $request)
    {
	    $pathInfo = $request->getPathInfo();
        $requestUri = $request->getRequestUri();

        $url = $requestUri .'admin';

	    return $this->redirect($url, 301);
	    #return new Response("$url", 200);
    }

    /**
     * Show admin page
     *
     * @Route("/admin", name="admin_show")
     */
    public function getAdmin(Request $request)
    {
        $authenticated = ['a' => false, 'z' => ['success' => 0, 'err' => 'none']];

        $now = new \DateTime();
        $utils = new Utilities();
        $workflow = new Workflow();

        switch ($request->getMethod()) {
            case 'POST':
                $ldap = new LDAPService();
                $user = $request->request->get('eid');
                $password = $request->request->get('pw');

                try {
                    if ($ldap->authenticate($user, $password)) {
                        $details = $ldap->match($user);
                        $session = $request->hasSession() ? $request->getSession() : new Session();
                        $session->set('username', $details[0]['cn']);
                        $authenticated['a'] = true;
                        $authenticated['z'] = $utils->getAuthorizedUsers($details[0]['cn']);
                    } else {
                        $authenticated['z']['err'] = 'Invalid username/password combination';
                    }
                } catch (\Exception $e) {
                    switch ($e->getMessage()) {
                        case 'no such user':
                            $authenticated['z']['err'] = 'No such user';
                        break;
                        case 'invalid id':
                            $authenticated['z']['err'] = 'Please use your official UCT staff number';
                        break;
                    }
                }
            break;
            default:
                $session = $request->hasSession() ? $request->getSession() : new Session();
                $authenticated['a'] = $session->get('username') ? true : false;
                if ($session->get('username')) {
                    $authenticated['z'] = $utils->getAuthorizedUsers($session->get('username'));
                }
            break;
        }

        $data = [ 'dept' => 'CILT', 'authenticated' => $authenticated, 'workflow' => $workflow->getWorkflow() ];
        //return new Response(json_encode($data), 201);
        //return new Response(json_encode($authenticated['z']), 201);

        if ($authenticated['z']['success']) {

            $data['departments'] = $utils->getAllCourses();

            $ar = [];
            if ($data['departments']['success'] == '1') {
                foreach ($data['departments']['result'] as $a) {
                    array_push($ar, substr($a['dept'], 0, 1));
                }
            }
            $data['list'] = array_unique($ar);

            //$data['courses'] =
            return $this->render('admin.html.twig', $data);
        } else {
            return $this->render('admin_login.html.twig', $authenticated['z']);
        }
    }

    /**
     * Show series admin page
     *
     * @Route("/series", name="series_admin_show")
     */
    public function showSeries(Request $request)
    {
        $authenticated = ['a' => false, 'z' => ['success' => 0, 'err' => 'none']];

        $now = new \DateTime();
        $utils = new Utilities();
        $workflow = new Workflow();

        switch ($request->getMethod()) {
            case 'POST':
                $ldap = new LDAPService();
                $user = $request->request->get('eid');
                $password = $request->request->get('pw');

                try {
                    if ($ldap->authenticate($user, $password)) {
                        $details = $ldap->match($user);
                        $session = $request->hasSession() ? $request->getSession() : new Session();
                        $session->set('username', $details[0]['cn']);
                        $authenticated['a'] = true;
                        $authenticated['z'] = $utils->getAuthorizedUsers($details[0]['cn']);
                    } else {
                        $authenticated['z']['err'] = 'Invalid username/password combination';
                    }
                } catch (\Exception $e) {
                    switch ($e->getMessage()) {
                        case 'no such user':
                            $authenticated['z']['err'] = 'No such user';
                        break;
                        case 'invalid id':
                            $authenticated['z']['err'] = 'Please use your official UCT staff number';
                        break;
                    }
                }
            break;
            default:
                $session = $request->hasSession() ? $request->getSession() : new Session();
                $authenticated['a'] = $session->get('username') ? true : false;
                if ($session->get('username')) {
                    $authenticated['z'] = $utils->getAuthorizedUsers($session->get('username'));
                }
            break;
        }

        $data = [ 'dept' => 'CILT', 'authenticated' => $authenticated, 'workflow' => $workflow->getWorkflow() ];
        //return new Response(json_encode($data), 201);
        //return new Response(json_encode($authenticated['z']), 201);

        if ($authenticated['z']['success']) {

            $data['departments'] = $utils->getAllCourses();

            $ar = [];
            if ($data['departments']['success'] == '1') {
                foreach ($data['departments']['result'] as $a) {
                    array_push($ar, substr($a['dept'], 0, 1));
                }
            }
            $data['list'] = array_unique($ar);

            //$data['courses'] =
            return $this->render('series.html.twig', $data);
        } else {
            return $this->render('series_login.html.twig', $authenticated['z']);
        }
    }

    /**
     * View the series according to the hash it receives
     *
     * @Route("/view-series/{hash}")
     */
    public function viewSeriesFromHash($hash, Request $request)
    {
        $authenticated = ['a' => false, 'z' => '0'];

        $now = new \DateTime();
        $utils = new Utilities();
        $data = $utils->getSeries($hash);

        switch ($request->getMethod()) {
            case 'POST':
                $ldap = new LDAPService();
                $user = $request->request->get('eid');
                $password = $request->request->get('pw');

                try {
                    if ($ldap->authenticate($user, $password)) {
                        $details = $ldap->match($user);
                        $session = $request->hasSession() ? $request->getSession() : new Session();
                        $session->set('username', $details[0]['cn']);
                        $authenticated['a'] = true;
                        $z = $utils->getAuthorizedUsers($details[0]['cn']);
                        if ($z) {
                            if ($z['success']) {
                                $authenticated['z'] = $z['result'][0];
                            }
                        }
                    } else {
                        $authenticated['z'] = 'Invalid username/password combination';
                    }
                } catch (\Exception $e) {
                    switch ($e->getMessage()) {
                        case 'no such user':
                            $authenticated['z'] = 'No such user';
                        break;
                        case 'invalid id':
                            $authenticated['z'] = 'Please use your official UCT staff number';
                        break;
                    }
                }
            break;
            default:
                $session = $request->hasSession() ? $request->getSession() : new Session();
                $authenticated['a'] = $session->get('username') ? true : false;

                $z = $utils->getAuthorizedUsers($session->get('username'));
                if ($z) {
                    if ($z['success']) {
                        $authenticated['z'] = $z['result'][0];
                    }
                }
            break;
        }

        if (!$data['success']) {
            return $this->render('error.html.twig', $data);
        }
        $data = $data['result'][0];

        $data['hash'] = $hash;
        $data['authenticated'] = $authenticated;

        $ocService = new OCRestService();
        $metadata = $ocService->getSeriesMetadata($data['series_id']);
        foreach($metadata as $struct) {
            $tmp = [];
            foreach($struct['fields'] as $field) {
                $tmp[ str_replace("-","_",$field['id'])] = $field['value'];
            }
            switch ($struct['flavor']) {
                case 'dublincore/series':
                    $data['dublincore'] = $tmp;
                    break;
                case 'ext/series':
                    $data['ext'] = $tmp;
                    break;
            }
        }
        $ar = $ocService->getEventsForSeries($data['series_id']);
        if (isset($ar['result'])) {

            $ar['result'] = array_filter($ar['result'], function($obj){
                if (isset($obj['mediaType'])) {
                    return ($obj['mediaType'] == "AudioVisual");
                }
                return false;
            });

            $func = function($event) {

                if ($event['mediaType'] == "AudioVisual") {
                    if (isset($event['mediapackage'])) {
                        $p = $event['mediapackage'];

                        // get preview
                        // $previews = $p['attachments']['attachment'];
                        $previews = array_values(array_filter(
                            array_map(function($track){
                                $pass = ($track["mimetype"] == "image/jpeg") && strpos($track['type'], "search+preview");
                                if (isset($track["tags"]) && (gettype($track["tags"]) == "array")) {
                                    if (count($track["tags"]) > 0) {
                                        if (gettype($track["tags"]["tag"]) == 'string') {
                                            $pass = $pass && ($track["tags"]["tag"] == "engage-download");
                                        } else {
                                            $pass = $pass && in_array("engage-download", $track["tags"]["tag"]);
                                        }
                                    }
                                }

                                if ($pass) {
                                    return (object) array('flavor' => explode("/", $track['type'])[0],
                                        'url' => $track['url'],
                                        'ref' => $track['ref']
                                    );
                                }
                            }, $p['attachments']['attachment']), function($item) {
                                return (gettype($item) != "NULL");
                            }));

                        // get downloads
                        $downloads = $p['media']['track'];
                        $downloads = array_filter(
                                array_map(function($track) use (&$previews) {
                                    // "engage-download"
                                    if (($track["mimetype"] == "video/mp4") && (in_array("atom", $track["tags"]["tag"]))) {
                                        $flavor = explode("/", $track['type'])[0];

                                        $img = array_filter($previews,
                                            function ($e) use (&$flavor) {
                                                return $e->flavor == $flavor;
                                            }
                                        );

                                        return (object) array(
                                            'flavor' => $flavor,
                                            'quality' => implode(array_filter($track["tags"]["tag"], function($str) {
                                                return(strpos($str, 'quality'));
                                                })),
                                            'img' => (count($img) > 0 ? array_values($img)[0]->url :''),
                                            'url' => $track['url'],
                                            'video' => $track['video']['resolution']
                                        );
                                    }
                                }, $p['media']['track']), function($item) {
                                    return (gettype($item) != "NULL");
                                });

                        $q_ar = array_column($downloads, 'quality');

                        if (array_search('high-quality', $q_ar) >= 0) {
                            // we have high quality
                            $downloads = array_filter($downloads, function($item) {
                                return ($item->quality == "high-quality") || ($item->quality == "");
                            });
                        } elseif (array_search('medium-quality', $q_ar) >= 0) {
                            // we have medium quality
                            $downloads = array_filter($downloads, function($item) {
                                return ($item->quality == "medium-quality") || ($item->quality == "");
                            });
                        } elseif (array_search('low-quality', $q_ar) >= 0) {
                            // we have low quality
                            $downloads = array_filter($downloads, function($item) {
                                return ($item->quality == "low-quality") || ($item->quality == "");
                            });
                        }

                        $event['media']  = (object) array('previews' => $previews, 'downloads' => array_values($downloads));
                    }

                    // remove unwanted fields
                    unset($event['mediapackage']);
                    unset($event['ocMediapackage']);
                    unset($event['segments']);
                    unset($event['keywords']);
                    unset($event['score']);
                    unset($event['org']);

                    return $event;
                }
            };

            $data['events'] = (object) array('offset' => $ar['offset'], 'limit' => $ar['limit'], 'total' => $ar['total'], //'query' => $ar['query'],
                                            'result' => array_map($func, $ar['result']));
        } else {
            $data['events'] = $ar;
        }

        // return new Response(json_encode($data), 201);
        return $this->render('series_view.html.twig', $data);
    }

}
