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
            $dept = new Department($data['dept'], $hash, $data['year'], false, false);    
            $data['details'] = $dept->getDetails();
            $data['readonly'] = ($now->diff(new \DateTime($data['date_course']))->format('%R') == '-');

            if (count($data['details']['courses']) == 0) {
                return $this->viewOptOut($hash, $request);
            }       

            //return new Response(json_encode($data), 201);
            return $this->render('department.html.twig', $data);   
        } else {
            $vula = new SakaiWebService();
            $ocService = new OCRestService();

            $course = new Course($data['course'], $hash, $data['year'], false);

            $data['details'] = $course->getDetails();
            $data['readonly'] = ($now->diff(new \DateTime($data['date_schedule']))->format('%R') == '-');
            $data['hasVulaSite'] = $vula->hasProviderId($data['course'], $data['year']);
            $data['hasOCSeries'] = $ocService->hasOCSeries($data['course'], $data['year']);
            $data['isTimetabled'] = $data['hasOCSeries'] ? $course->checkIsTimetabled() : false;

            // retrieve timetable information
            $json = file_get_contents('https://srvslscet001.uct.ac.za/timetable/?course='. $data['course'] .','. $data['year']);
            $data['timetable'] = json_decode($json);

            //return new Response(json_encode($data), 201);
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

                            $updated = $dept->updateOptoutStatus($session->get('username'), ['status' => $request->request->get('optout_confirm') ]);
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

                $str = $data['course'] ." course:  Automated Setup or Opt-out of Lecture Recording" . 
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
}
