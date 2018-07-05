<?php
// src/Controller/DepartmentController.php
namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use App\Service\Utilities;

class DepartmentController extends Controller
{
    /**
     * @Route("/view/{hash}")
     */
    public function viewDepartment($hash)
    {
        $utils = new Utilities();
        $data = $utils->getMail($hash);

        if (!$data['success']) {
            return $this->render('error.html.twig');
        } else {
            $data = $data['result'][0];
            $data['hash'] = $hash;
            $data['out_link'] = 'http://srvslscet001.uct.ac.za/optout/dept';

            
        }

        //return new Response(json_encode($data), 201);
        return $this->render('department.html.twig', $data);
    }
}