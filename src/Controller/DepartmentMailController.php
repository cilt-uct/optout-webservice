<?php
// src/Controller/DepartmentMailController.php
namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

class DepartmentMailController extends Controller
{
    /**
     * @Route("/dept_mail")
     */
    public function body()
    {
        return $this->render('department_mail.html.twig', 
            array(  'dept' => 'ZZZ',
                    'out_link' => 'http://srvslscet001.uct.ac.za/optout/dept',
                    'view_link' => 'http://srvslscet001.uct.ac.za/optout/dept'));
    }
}