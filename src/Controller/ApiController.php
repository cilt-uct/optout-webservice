<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\Annotation\Route;
use App\Entity\Department;
use App\Entity\Course;
use App\Service\LDAPService;
use App\Service\OCRestService;
use App\Service\SakaiWebService;
use App\Service\Utilities;

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

  /**
   * @Route("/dept")
   */
  public function getDepartments(Request $request) {
  }

  /**
   * @Route("/dept/{deptName}")
   */
  public function getDepartment($deptName, Request $request) {
    $session = new Session();
    $session->start();

    $username = $session->get('username');
    $deptHash = urldecode($request->headers->get('x-department'));

    try {
      $department = new Department($deptName, $deptHash);
    } catch (\Exception $e) {
      switch($e->getMessage()) {
        case 'no such dept':
          return new Response('No such dept', 404, ['Content-Type' => 'text/plain']);
          break;

        case 'invalid hash':
          return new Response('Authorisation required', 401, ['Content-Type' => 'text/plain']);
          break;

        default:
          return new Response($e->getMessage(), 500, ['Content-Type' => 'text/plain']);
      }
    }

    return new Response(json_encode($department->getDetails()), 200, ['Content-Type' => 'application/json']);
  }

  /**
   * @Route("/course")
   */
  public function refreshCourses(Request $request) {
    switch($request->getMethod()) {
      case 'PUT':
        $utils = new Utilities();
        try {
            $updateResults = $utils->refreshCourses();
            return new Response(json_encode($updateResults), 200, [
                'Content-Type' => 'text/plain'
              ]
            );
        } catch (\Exception $e) {
          return new Response($e->getMessage, 500);
        }
        break;

      default:
        return new Response('Only PUT supported right now', 405);
    }
  }

  /**
   * @Route("/dept/{deptName}/hash");
   */
  public function getDepartmentHash($deptName, Request $request) {
    $session = new Session();
    $session->start();

    $username = $session->get('username');
    $deptHash = urldecode($request->headers->get('x-department'));

    try {
      $department = new Department($deptName, null, null, true);
    } catch (\Exception $e) {
      switch($e->getMessage()) {
        case 'no such dept':
          return new Response('No such dept', 404, ['Content-Type' => 'text/plain']);
          break;

        case 'invalid hash':
          return new Response('Authorisation required', 401, ['Content-Type' => 'text/plain']);
          break;

        default:
          return new Response($e->getMessage(), 500, ['Content-Type' => 'text/plain']);
      }
    }

    return new Response($department->getHash(), 200, ['Content-Type' => 'text/plain']);
  }


  /**
   * @Route("/course/{courseCode}")
   */
  public function getCourse($courseCode, Request $request) {
    $year = date('Y');
    $vula = new SakaiWebService();
    $ocService = new OCRestService();
    try {
      $course = new Course($courseCode, null, null, true);
      $response = $course->getDetails();
      $response['hasVulaSite'] = $vula->hasProviderId($courseCode, $year);
      $response['hasOCSeries'] = $ocService->hasOCSeries($courseCode, $year);
      return new Response(json_encode($response), 200, ['Content-Type' => 'application/json']);
    } catch (\Exception $e) {
      return new Response($e->getMessage(), 500);
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
