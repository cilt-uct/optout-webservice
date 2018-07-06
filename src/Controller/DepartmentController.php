<?php
// src/Controller/DepartmentController.php
namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use App\Entity\Course;
use App\Entity\Department;
use App\Service\LDAPService;
use App\Service\Utilities;

class DepartmentController extends Controller
{
    /**
     * @Route("/view/{hash}")
     */
    public function viewDepartment($hash, Request $request)
    {
        $authenticated = ['a' => false, 'z' => '0'];
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

        $utils = new Utilities();
        $data = $utils->getMail($hash);
        
        if (!$data['success']) {
            return $this->render('error.html.twig', $data);
        } else {
            $data = $data['result'][0];
            $data['hash'] = $hash;
            $data['out_link'] = 'http://srvslscet001.uct.ac.za/optout/dept';
            $data['authenticated'] = $authenticated;
            
            $dept = new Department($data['dept'], $hash, $data['year'], false);    
            $data['details'] = $dept->getDetails();
        }

        //return new Response(json_encode($data), 201);
        return $this->render('department.html.twig', $data);
    }
}