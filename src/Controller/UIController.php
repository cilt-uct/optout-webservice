<?php
// src/Controller/UIController.php
namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use App\Entity\Course;
use App\Entity\Department;
use App\Service\LDAPService;
use App\Service\Utilities;

class UIController extends Controller
{
    /**
     * @Route("/view/{hash}")
     * 
     * View the page according to the hash it receives 
     */
    public function viewFromHash($hash, Request $request)
    {
        $authenticated = ['a' => false, 'z' => '0'];
        
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
            $data['out_link'] = 'http://srvslscet001.uct.ac.za/optout/out/'. $hash;
            $data['authenticated'] = $authenticated;
        }

        if ($data['course'] === null ) {
            $dept = new Department($data['dept'], $hash, $data['year'], false);    
            $data['details'] = $dept->getDetails();

            if (count($data['details']['courses']) == 0) {
                return $this->viewOptOut($hash, $request);
            }       

            return $this->render('department.html.twig', $data);   
        } else {

            $course = new Course($data['course'], $hash, $data['year'], false);    
            $data['details'] = $course->getDetails();
            
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
        $utils = new Utilities();
        $data = $utils->getMail($hash);

        // get department
        $dept = new Department($data['result'][0]['dept'], $hash, $data['result'][0]['year']);

        $authenticated = ['a' => false, 'z' => '0'];
        $confirmed = $dept->isOptOut;

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

        if (!$data['success']) {
            return $this->render('error.html.twig', $data);
        } else {
            $data = $data['result'][0];
            $data['hash'] = $hash;
            $data['out_link'] = 'http://srvslscet001.uct.ac.za/optout/out/'. $hash;
            $data['authenticated'] = json_encode($authenticated);
            $data['confirmed'] = json_encode($dept->getDetails());

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
                            'out_link' => 'http://srvslscet001.uct.ac.za/optout/out/'. $hash,
                            'view_link' => 'http://srvslscet001.uct.ac.za/optout/view/'. $hash));
            } else {
                $course = new Course($data['course'], $hash, $data['year'], false);
                $details = $course->getDetails();

                return $this->render('course_mail.html.twig', 
                    array(  'dept' => $data['dept'],
                            'course' => $data['course'],
                            'name' => $data['name'],
                            'date' => $data['date_schedule'],
                            'out_link' => 'http://srvslscet001.uct.ac.za/optout/out/'. $hash,
                            'view_link' => 'http://srvslscet001.uct.ac.za/optout/view/'. $hash));
            }
        } else {
            return new Response("ERROR_MAIL_HASH", 500);
        }
    }
}