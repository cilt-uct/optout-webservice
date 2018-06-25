<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ApiController extends Controller
{
  /**
   * @Route("/api")
   */
  public function number()
  {
    $number = random_int(0, 100);

    return new Response(
      "<html><body>This is a random number generated just for you: $number</body></html>"
    );
  }
    
}
