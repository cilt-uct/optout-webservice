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
    try {
      $isUctEmail = filter_var($searchStr, FILTER_VALIDATE_EMAIL) && strpos($searchStr, '@uct.ac.za') > -1;
      $isNumeric = is_numeric($searchStr);

      $result = [];
      if ($isUctEmail) {
          $result['vula'] = $this->searchVula(null, null, $searchStr);
          $result['ldap'] = $this->searchLdap($result['vula']['username']);
      }
      else if ($isNumeric) {
          $result['ldap'] = $this->searchLdap($searchStr);
          $result['vula'] = $this->searchVula($result['ldap'][0]['cn'], null, null);
      }

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
   * @Route("/search/vula/{searchStr}")
   */
  public function searchVulaEndpoint($searchStr, Request $request) {
    try {
      $result = [];
    //  if ($isUctEmail) {
          $result['vula'] = $this->searchVula(null, null, $searchStr);
          $result['ldap'] = $this->searchLdap($result['vula']['username']);
      // }
      // else if ($isNumeric) {
      //    $result['ldap'] = $this->searchLdap($searchStr);
      //    $result['vula'] = $this->searchVula($result['ldap'][0]['cn'], null, null);
      // }

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

  private function searchLdap($searchStr) {
    $ldap = new LDAPService();
    return $ldap->match($searchStr);
  }

  private function searchVula($eid, $ip = null, $email = null) {
    return (new User($eid, $ip, $email))->getDetails();
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
    $fullCourse = urldecode($request->headers->get('x-entity-courses')) == '1';
    try {
      $department = new Department($deptName, $deptHash, '', false, !$fullCourse);
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

    $result = $department->getDetails();
    $result['fullCourse'] = $fullCourse;
    return new Response(json_encode($result), 200, ['Content-Type' => 'application/json']);
  }

  private function updateDeptCourses($deptName, $request) {
    $session = $request->hasSession() ? $request->getSession() : new Session();
    if (!$session->get('username')) {
      return new Response("Please login to make changes", 401, ['Content-Type' => 'text/plain']);
    }

    $deptHash = urldecode($request->headers->get('x-entity-hash'));
    $data = json_decode($request->getContent(), true);
    $workflow = (new Workflow)->getWorkflow();

    /*
    $data = [{
      "course": course_code,
      "changes": [{
          "field": name (convenorName / convenorEmail),
          "from": old value,
          "to": new value
        }]
    }]
    */

    $conflicts = [];
    $someSuccess = false;

    if (!is_array($data)) {
      return new Response("Please supply changes as a JSON array", 400, ['Content-Type' => 'text/plain']);
    }

    try {
      // get full department with all courses
      $dept = new Department($deptName, $deptHash, null, false, false);

      $courseCodesToUpdate = array_map(function($change) {
        if (isset($change['course'])) {
          return $change['course'];
        }
      }, $data);

      //if ($courseCodesToUpdate)

      $coursesToUpdate = array_filter($dept->courses, function($course) use ($courseCodesToUpdate) {
        return in_array($course->courseCode, $courseCodesToUpdate);
      });

      $coursesToUpdate = array_reduce($coursesToUpdate, function($result, $course) {
        $result[$course->courseCode] = $course;
        return $result;
      }, []);

      // loop through values
      foreach ($data as $index => $update) {

        // we want to change a course value
        if (isset($update['course'])) {

          // nope we don't have that course :p
          if (!isset($coursesToUpdate[$update['course']])) {
            continue;
          }

          try {
              // get the correct course and run update on it
              $coursesToUpdate[$update['course']]->updateCourse($update['changes'], $session->get('username'), $workflow['oid']);
              $someSuccess = true;
          } catch (\Exception $e) {
              switch($e->getMessage()) {
                  case 'conflict':
                      $coursesToUpdate[$update['course']]->fetchDetails();
                      $conflicts[] = $coursesToUpdate[$update['course']]->getDetails();
                      $conflicts[] = [
                        'changes' => $update['changes'],
                        'username' => $session->get('username'),
                        'wid' => $workflow['oid']
                      ];
                      break;
                  default:
                      throw new \Exception($e->getMessage());
              }
          }
        } // if updating

        // we want to change the dept value
        if (isset($update['dept'])) {

          try {
            // get the correct course and run update on it
            $dept->updateDepartment($update['changes'], $session->get('username'));
            $someSuccess = true;
          } catch (\Exception $e) {
            switch($e->getMessage()) {
                case 'conflict':
                    $conflicts[] = $dept->getDetails();
                    break;
                default:
                    throw new \Exception($e->getMessage());
            }
          }
        } // if updating dept
      } // foreach
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
    $workflow = (new Workflow)->getWorkflow();

    try {
      $dept = new Department($deptName, $deptHash, null, false);
      $updateStatus = $dept->updateOptoutStatus($session->get('username'), $data, $workflow['oid']);
    } catch(\Exception $e) {
      $statusCode = 500;
      switch($e->getMessage()) {
          case 'Authorisation required':
              $statusCode = 401;
              break;
      }
      return new Response($e->getMessage(), $statusCode, ['Content-Type' => 'text/plain']);
    }
    return new Response(json_encode($updateStatus), 201);
  }

  private function updateCourseOptoutStatus($courseCode, Request $request) {
    $session = $request->hasSession() ? $request->getSession() : new Session();
    $courseHash = urldecode($request->headers->get('x-entity-hash'));
    $data = json_decode($request->getContent(), true);
    $workflow = (new Workflow)->getWorkflow();

    try {
      $course = new Course($courseCode, $courseHash, null, false);
      $updateStatus = $course->updateOptoutStatus($session->get('username'), $data, $workflow['oid']);
    } catch(\Exception $e) {
      $statusCode = 500;
      switch($e->getMessage()) {
          case 'Authorisation required':
              $statusCode = 401;
              break;
      }
      return new Response($e->getMessage(), $statusCode, ['Content-Type' => 'text/plain']);
    }
    return new Response(json_encode($updateStatus), 201);
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
   * @Route("/departments")
   */
  public function refreshDepartments(Request $request) {
    switch($request->getMethod()) {
      case 'PUT':
        $utils = new Utilities();
        try {
            $updateResults = $utils->refreshDepartments();
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
   * @Route("/api/v0/timetable/{courseCode}/{year}")
   *
   * THIS DOESN'T WORK
   */
  public function processMail($courseCode, $year, Request $request)
  {

    // retrieve timetable information
    $json = file_get_contents('https://srvslscet001.uct.ac.za/timetable/?course='. $courseCode .','. $year);
    $result = json_decode($json);

    if (json_last_error() == JSON_ERROR_NONE) {
      return new Response($json, 201);
    }

    return new Response('Error loading timetable', 201);
  }

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
      $response['isTimetabled'] = $response['hasOCSeries'] ? $course->checkIsTimetabledInOC() : false;
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
      set_time_limit(3000);
      switch ($request->getMethod()) {
          case 'GET':
              return $this->runWorkflowMonitor($request);
              break;
          default:
              return new Response('Only GET supported right now', 405);
      }
      set_time_limit(30);
  }

  private function runWorkflowMonitor(Request $request) {
      $result = (new Workflow)->run();

      return new Response(json_encode($result), 201);
  }

  /**
   * @Route("/api/v0/series/")
   */
  public function getSeries( Request $request) {
    switch ($request->getMethod()) {
      case 'GET':
        $utils = new Utilities();
        try {
          $response = $utils->getAllSeries();
          return new Response(json_encode($response), 200, ['Content-Type' => 'application/json']);
        } catch (\Exception $e) {
          return new Response($e->getMessage(), 500);
        }
    // https://media.uct.ac.za/admin-ng/series/series.json?sortorganizer=&sort=&filter=&offset=0&optedOut=false&limit=100
    //   case 'GET':
    //     $ocService = new OCRestService();
    //     try {
    //       $response = $ocService->getAll();
    //       return new Response(json_encode($response), 200, ['Content-Type' => 'application/json']);
    //     } catch (\Exception $e) {
    //       return new Response($e->getMessage(), 500);
    //     }
    default:
        return new Response('Method not implemented', 405, ['Content-Type' => 'text/plain']);
    }
  }

  /**
   * @Route("/api/v0/episode/{eventId}")
   */
  public function getEpisode($eventId, Request $request) {
    switch ($request->getMethod()) {
    // https://mediadev.uct.ac.za/search/episode.json?id=596ff927-79bc-4a15-a39f-80ea8c7f16e0
      case 'GET':
        $ocService = new OCRestService();
        try {
          $response = $ocService->getEventForPlayback(str_replace('_','-',$eventId));
          return new Response(json_encode($response), 200, ['Content-Type' => 'application/json']);
        } catch (\Exception $e) {
          return new Response($e->getMessage(), 500);
        }
    default:
        return new Response('Method not implemented', 405, ['Content-Type' => 'text/plain']);
    }
  }

}
