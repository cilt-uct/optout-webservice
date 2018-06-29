<?php
// src/Controller/DeparmentMailController.php
namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

class DeparmentMailController extends Controller
{
    /**
     * @Route("/dept_mail")
     */
    public function body()
    {
        $number = random_int(0, 100);

        return $this->render('department_mail.html.twig', array('number' => $number));
    }
}