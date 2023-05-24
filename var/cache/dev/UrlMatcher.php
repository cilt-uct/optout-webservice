<?php

/**
 * This file has been auto-generated
 * by the Symfony Routing Component.
 */

return [
    false, // $matchHost
    [ // $staticRoutes
        '/me' => [[['_route' => 'app_api_me', '_controller' => 'App\\Controller\\ApiController::me'], null, null, null, false, false, null]],
        '/authenticate' => [[['_route' => 'app_api_auth', '_controller' => 'App\\Controller\\ApiController::auth'], null, null, null, false, false, null]],
        '/course' => [[['_route' => 'app_api_refreshcourses', '_controller' => 'App\\Controller\\ApiController::refreshCourses'], null, null, null, false, false, null]],
        '/departments' => [[['_route' => 'app_api_refreshdepartments', '_controller' => 'App\\Controller\\ApiController::refreshDepartments'], null, null, null, false, false, null]],
        '/monitor_batch' => [[['_route' => 'app_api_monitor_batch', '_controller' => 'App\\Controller\\ApiController::monitor_batch'], null, null, null, false, false, null]],
        '/monitor' => [[['_route' => 'app_api_monitor', '_controller' => 'App\\Controller\\ApiController::monitor'], null, null, null, false, false, null]],
        '/api/v0/series' => [[['_route' => 'app_api_getseries', '_controller' => 'App\\Controller\\ApiController::getSeries'], null, null, null, true, false, null]],
        '/api/v0/dass' => [[['_route' => 'app_api_getsurveyresultemails', '_controller' => 'App\\Controller\\ApiController::getSurveyResultEmails'], null, null, null, true, false, null]],
        '/' => [[['_route' => 'Main', '_controller' => 'App\\Controller\\UIController::defaultMain'], null, null, null, false, false, null]],
        '/admin' => [[['_route' => 'admin_show', '_controller' => 'App\\Controller\\UIController::getAdmin'], null, null, null, false, false, null]],
        '/series' => [[['_route' => 'series_admin_show', '_controller' => 'App\\Controller\\UIController::showSeries'], null, null, null, false, false, null]],
        '/survey' => [[['_route' => 'app_ui_showsurveyoverview', '_controller' => 'App\\Controller\\UIController::showSurveyOverview'], null, null, null, true, false, null]],
        '/generate_result_emails' => [[['_route' => 'app_ui_generateresultemails', '_controller' => 'App\\Controller\\UIController::generateResultEmails'], null, null, null, true, false, null]],
    ],
    [ // $regexpList
        0 => '{^(?'
                .'|/_error/(\\d+)(?:\\.([^/]++))?(*:35)'
            .')/?$}sD',
    ],
    [ // $dynamicRoutes
        35 => [
            [['_route' => '_preview_error', '_controller' => 'error_controller::preview', '_format' => 'html'], ['code', '_format'], null, null, false, true, null],
            [null, null, null, null, false, false, 0],
        ],
    ],
    null, // $checkCondition
];
