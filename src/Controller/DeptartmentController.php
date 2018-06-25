<?php
// src/Controller/DeptartmentController.php
namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

class DeptartmentController extends Controller
{
    /**
     * @Route("/dept")
     */
    public function body()
    {
        $number = random_int(0, 100);

        return $this->render('department.html.twig', array('number' => $number));
    }
}