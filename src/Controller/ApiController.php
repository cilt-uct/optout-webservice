<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\Annotation\Route;
use App\Entity\Course;
use App\Entity\Department;
use App\Entity\OrganisationalEntityFactory;
use App\Entity\User;
use App\Entity\Workflow;
use App\Service\LDAPService;
use App\Service\OCRestService;
use App\Service\SakaiWebService;
use App\Service\Utilities;

class ApiController extends Controller
{

  /**
   * @Route("/search/{searchStr}")
   */
  public function search($searchStr, Request $request) {
    $ldap = new LDAPService();
    try {
      $result = $ldap->match($searchStr);
      $utils = new Utilities();
      $result = array_map(function($r) use ($utils, $searchStr, $request) {
          $ret = [
              'ldap' => $r,
              'vula' => (new User($searchStr, $request->getClientIp()))->getDetails()
          ];
          return $ret;
      }, $result);
      return new Response(
        json_encode($result),
        200,
        [
          'Content-Type' => 'application/json'
        ]
      );
    } catch (\Exception $e) {
      var_dump($e);
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
   * @Route ("/me")
   */
  public function me(Request $request) {
    $session = $request->hasSession() ? $request->getSession() : new Session();
    return new Response(json_encode($session->all()), 200, ['Content-Type' => 'application/json']);
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
   * @Route("/api/v0/dept/{deptName}")
   */
  public function departmentEndpoint($deptName, Request $request) {
    switch ($request->getMethod()) {
        case 'PUT':
            return $this->updateDeptOptoutStatus($deptName, $request);
            break;

        case 'PATCH':
            return $this->updateDeptCourses($deptName, $request);
            break;

        default:
            return $this->getDepartmentInfo($deptName, $request);
            break;
    }
  }

  private function getDepartmentInfo($deptName, Request $request) {
    $deptHash = urldecode($request->headers->get('x-entity-hash'));
    try {
      $department = new Department($deptName, $deptHash);
    } catch (\Exception $e) {
      switch($e->getMessage()) {
        case 'no such dept':
          return new Response('No such dept', 404, ['Content-Type' => 'text/plain']);
          break;

        case 'invalid hash':
          return new Response('invalid hash', 401, ['Content-Type' => 'text/plain']);
          break;

        default:
          return new Response($e->getMessage(), 500, ['Content-Type' => 'text/plain']);
      }
    }

    return new Response(json_encode($department->getDetails()), 200, ['Content-Type' => 'application/json']);
  }

  private function updateDeptCourses($deptName, $request) {
    $session = $request->hasSession() ? $request->getSession() : new Session();
    if (!$session->get('username')) {
      return new Response("Please login to make changes", 401, ['Content-Type' => 'text/plain']);
    }

    $deptHash = urldecode($request->headers->get('x-entity-hash'));
    $data = json_decode($request->getContent(), true);

    $conflicts = [];
    $someSuccess = false;

    if (!is_array($data)) {
      return new Response("Please supply changes as a JSON array", 400, ['Content-Type' => 'text/plain']);
    }

    try {
      $dept = new Department($deptName, $deptHash, null, false);
      $courseCodesToUpdate = array_map(function($change) {
        return $change['course'];
      }, $data);
      $coursesToUpdate = array_filter($dept->courses, function($course) use ($courseCodesToUpdate) {
        return in_array($course->courseCode, $courseCodesToUpdate);
      });
      $coursesToUpdate = array_reduce($coursesToUpdate, function($result, $course) {
        $result[$course->courseCode] = $course;
        return $result;
      }, []);

      foreach ($data as $index => $update) {
        if (!isset($coursesToUpdate[$update['course']])) {
          continue;
        }

        try {
            $coursesToUpdate[$update['course']]->updateCourse($update['changes'], $session->get('username'));
            $someSuccess = true;
        } catch (\Exception $e) {
            switch($e->getMessage()) {
                case 'conflict':
                    $coursesToUpdate[$update['course']]->fetchDetails();
                    $conflicts[] = $coursesToUpdate[$update['course']]->getDetails();
                    break;
                default:
                    throw new \Exception($e->getMessage());
            }
        }
      }
    } catch (\Exception $e) {
      $statusCode = 500;
      switch($e->getMessage()) {
          case 'Authorisation required':
              $statusCode = 401;
              break;
      }
      return new Response($e->getMessage(), $statusCode, ['Content-Type' => 'text/plain']);
    }

    if (sizeof($conflicts) === 0) {
      return new Response('', 204);
    }
    else if ($someSuccess) {
      return new Response(json_encode($conflicts), 200, ['Content-Type' => 'application/json']);
    }
    else {
      return new Response(json_encode($conflicts), 409, ['Content-Type' => 'application/json']);
    }
  }

  private function updateDeptOptoutStatus($deptName, Request $request) {
    $session = $request->hasSession() ? $request->getSession() : new Session();
    $deptHash = urldecode($request->headers->get('x-entity-hash'));
    $data = json_decode($request->getContent(), true);
    
    try {
      $dept = new Department($deptName, $deptHash, null, false);
      $updateStatus = $dept->updateOptoutStatus($session->get('username'), $data);
    } catch(\Exception $e) {
      $statusCode = 500;
      switch($e->getMessage()) {
          case 'Authorisation required':
              $statusCode = 401;
              break;
      }
      return new Response($e->getMessage(), $statusCode, ['Content-Type' => 'text/plain']);
    }
    return new Response($updateStatus, 201);
  }

  private function updateCourseOptoutStatus($courseCode, Request $request) {
    $session = $request->hasSession() ? $request->getSession() : new Session();
    $courseHash = urldecode($request->headers->get('x-entity-hash'));
    $data = json_decode($request->getContent(), true);

    try {
      $course = new Course($courseCode, $courseHash, null, false);
      $updateStatus = $course->updateOptoutStatus($session->get('username'), $data);
    } catch(\Exception $e) {
      $statusCode = 500;
      switch($e->getMessage()) {
          case 'Authorisation required':
              $statusCode = 401;
              break;
      }
      return new Response($e->getMessage(), $statusCode, ['Content-Type' => 'text/plain']);
    }
    return new Response($updateStatus, 201);
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
   * @Route("/api/v0/{entityType}/{entityName}/hash");
   */
  public function getEntityHash($entityType, $entityName, Request $request) {
    try {
      $entity = OrganisationalEntityFactory::getEntity($entityType, $entityName);
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

    return new Response($entity->getHash(), 200, ['Content-Type' => 'text/plain']);
  }

  /**
   * @Route("/api/v0/{entityType}/{entityName}");
  public function updateOptout($entityType, $entityName, Request $request) {
    $session = $request->hasSession() ? $request->getSession() : new Session();
  }
   */


  /**
   * @Route("/api/v0/course/{courseCode}")
   */
  public function courseEndpoint($courseCode, Request $request) {
    switch ($request->getMethod()) {
      case 'GET':
        return $this->getCourse($courseCode, $request);

      case 'PUT':
        return $this->updateCourseOptoutStatus($courseCode, $request);

      default:
        return new Response('Method not implemented', 405, ['Content-Type' => 'text/plain']);

    }
  }

  private function getCourse($courseCode, Request $request) {
    $courseHash = urldecode($request->headers->get('x-entity-hash'));
    $year = date('Y');
    $vula = new SakaiWebService();
    $ocService = new OCRestService();
    try {
      $course = new Course($courseCode, $courseHash, $year, false);
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
        $details = $ldap->match($user);
        $session = $request->hasSession() ? $request->getSession() : new Session();
        $session->set('username', $details[0]['cn']);
        return new Response("OK", 200);
      }

      return new Response("Invalid username/password combination", 401);

    } catch (\Exception $e) {
      var_dump($e);
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

  /**
   * @Route("/monitor")
   */
  public function monitor(Request $request)
  {
      switch ($request->getMethod()) {
          case 'GET':
              return $this->runWorkflowMonitor($request);
              break;
          default:
              return new Response('Only GET supported right now', 405);
      }
  }

  private function runWorkflowMonitor(Request $request) {
      $result = (new Workflow)->run();

      return new Response(json_encode($result), 201);
  }

  /**
   * @Route("/process/mail")
   */
  public function processMail(Request $request, \Swift_Mailer $mailer) {

    //$transport = new \Swift_SendmailTransport('/usr/sbin/exim -bs');
    //$mailer = new \Swift_Mailer($transport);

    $message = (new \Swift_Message('Automated Setup of Lecture Recording: Department Opt-Out process'))
        ->setFrom(['help@vula.uct.ac.za' => 'Lecture Recording Team'])
        ->setTo('corne.oosthuizen@uct.ac.za')
        ->setBody(
            $this->renderView(
                'department_mail.html.twig',
                array('dept' => 'ZZZ',
                      'out_link' => 'http://srvslscet001.uct.ac.za/optout/dept',
                      'view_link' => 'http://srvslscet001.uct.ac.za/optout/dept')
            )
        );

    $message->setReturnPath('help@vula.uct.ac.za'); // bounces will be sent to this address
    $result = $mailer->send($message, $failures);

    if (!$result) {
      return new Response($failures, 201);
    }
    return new Response('email sent successfully', 201);  
  }

}
