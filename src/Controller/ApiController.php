<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use App\Service\LDAPService;

class ApiController extends Controller
{

  /**
   * @Route("/search/{searchStr}")
   */
  public function search($searchStr) {
    $ldap = new LDAPService();
    try {
      $result = $ldap->match($searchStr);
      return new Response(
        json_encode($result),
        200,
        [
          'Content-Type' => 'application/json'
        ]
      );
    } catch (\Exception $e) {
      $response = [
        "text" => "Server error",
        "statusCode" => 500,
        "contentType" => [
          'Content-Type' => 'text/plain'
        ]
      ];
      switch($e->getMessage()) {
        case "no such user":
          $response['text'] = 'User not found';
          $response['statusCode'] = 404;
      }

      return new Response($response['text'], $response['statusCode'], $response['contentType']);
    }
  }

  /**
   * @Route("/authenticate")
   */
  public function auth(Request $request) {
    switch ($request->getMethod()) {
      case 'POST':
        return $this->handleAuthentication($request);
        break;

      default:
        return $this->displayAuthenticationForm($request);
    }
  }

  private function handleAuthentication($request) {
    $ldap = new LDAPService();
    $user = $request->request->get('username');
    $password = $request->request->get('password');

    try {
      if ($ldap->authenticate($user, $password)) {
        return new Response("OK", 200);
      }

      return new Response("Invalid username/password combination", 401);

    } catch (\Exception $e) {
      $response = [
        "text" => "Server error",
        "statusCode" => 500,
        "contentType" => [
          'Content-Type' => 'text/plain'
        ]
      ];
      switch ($e->getMessage()) {
        case 'no such user':
          $response['text'] = 'No such user';
          $response['statusCode'] = 404;
          break;

        case 'invalid id':
          $response['text'] = 'Please use your official UCT staff number';
          $response['statusCode'] = 400;
          break;
      }
      return new Response($response['text'], $response['statusCode'], $response['contentType']);
    }
  }

  private function displayAuthenticationForm(Request $request) {
    return new Response("<form method=post><p><input name=username /></p><p><input name=password type=password /></p><button>Login</button></form>");
  }
}
