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
        '/optout/admin' => [[['_route' => 'admin_show', '_controller' => 'App\\Controller\\UIController::getAdmin'], null, null, null, false, false, null]],
        '/series' => [[['_route' => 'series_admin_show', '_controller' => 'App\\Controller\\UIController::showSeries'], null, null, null, false, false, null]],
        '/survey' => [[['_route' => 'app_ui_showsurveyoverview', '_controller' => 'App\\Controller\\UIController::showSurveyOverview'], null, null, null, true, false, null]],
        '/generate_result_emails' => [[['_route' => 'app_ui_generateresultemails', '_controller' => 'App\\Controller\\UIController::generateResultEmails'], null, null, null, true, false, null]],
    ],
    [ // $regexpList
        0 => '{^(?'
                .'|/s(?'
                    .'|e(?'
                        .'|arch/(?'
                            .'|([^/]++)(*:32)'
                            .'|vula/([^/]++)(*:52)'
                        .')'
                        .'|ries_tocc/([^/]++)(*:78)'
                    .')'
                    .'|u(?'
                        .'|bject(?'
                            .'|/([^/]++)(*:107)'
                            .'|_series/([^/]++)(*:131)'
                        .')'
                        .'|rvey(?'
                            .'|/([^/]++)(*:156)'
                            .'|_test/([^/]++)(*:178)'
                        .')'
                    .')'
                .')'
                .'|/api/v0/(?'
                    .'|dept/([^/]++)(*:213)'
                    .'|([^/]++)/([^/]++)/hash(*:243)'
                    .'|timetable/([^/]++)/([^/]++)(*:278)'
                    .'|course/([^/]++)(*:301)'
                    .'|episode/([^/]++)(*:325)'
                .')'
                .'|/view(?'
                    .'|/([^/]++)(*:351)'
                    .'|\\-series/([^/]++)(*:376)'
                .')'
                .'|/out/([^/]++)(*:398)'
                .'|/mail(?'
                    .'|/([^/]++)(*:423)'
                    .'|_(?'
                        .'|s(?'
                            .'|eries/([^/]++)(*:453)'
                            .'|ubject_results/([^/]++)(*:484)'
                        .')'
                        .'|body_results/([^/]++)(*:514)'
                    .')'
                .')'
                .'|/downloadsurvey(?'
                    .'|_t/([^/]++)(*:553)'
                    .'|/([^/]++)(*:570)'
                .')'
                .'|/_error/(\\d+)(?:\\.([^/]++))?(?'
                    .'|(*:610)'
                .')'
            .')/?$}sD',
    ],
    [ // $dynamicRoutes
        32 => [[['_route' => 'app_api_search', '_controller' => 'App\\Controller\\ApiController::search'], ['searchStr'], null, null, false, true, null]],
        52 => [[['_route' => 'app_api_searchvulaendpoint', '_controller' => 'App\\Controller\\ApiController::searchVulaEndpoint'], ['searchStr'], null, null, false, true, null]],
        78 => [[['_route' => 'app_ui_getseriesmailtocc', '_controller' => 'App\\Controller\\UIController::getSeriesMailToCC'], ['hash'], null, null, false, true, null]],
        107 => [[['_route' => 'app_ui_getmailsubject', '_controller' => 'App\\Controller\\UIController::getMailSubject'], ['hash'], null, null, false, true, null]],
        131 => [[['_route' => 'app_ui_getseriesmailsubject', '_controller' => 'App\\Controller\\UIController::getSeriesMailSubject'], ['hash'], null, null, false, true, null]],
        156 => [[['_route' => 'app_ui_surveyfromhash', '_controller' => 'App\\Controller\\UIController::surveyFromHash'], ['hash'], null, null, false, true, null]],
        178 => [[['_route' => 'app_ui_surveyfromhashtest', '_controller' => 'App\\Controller\\UIController::surveyFromHashTest'], ['hash'], null, null, false, true, null]],
        213 => [[['_route' => 'app_api_departmentendpoint', '_controller' => 'App\\Controller\\ApiController::departmentEndpoint'], ['deptName'], null, null, false, true, null]],
        243 => [[['_route' => 'app_api_getentityhash', '_controller' => 'App\\Controller\\ApiController::getEntityHash'], ['entityType', 'entityName'], null, null, false, false, null]],
        278 => [[['_route' => 'app_api_processmail', '_controller' => 'App\\Controller\\ApiController::processMail'], ['courseCode', 'year'], null, null, false, true, null]],
        301 => [[['_route' => 'app_api_courseendpoint', '_controller' => 'App\\Controller\\ApiController::courseEndpoint'], ['courseCode'], null, null, false, true, null]],
        325 => [[['_route' => 'app_api_getepisode', '_controller' => 'App\\Controller\\ApiController::getEpisode'], ['eventId'], null, null, false, true, null]],
        351 => [[['_route' => 'app_ui_viewfromhash', '_controller' => 'App\\Controller\\UIController::viewFromHash'], ['hash'], null, null, false, true, null]],
        376 => [[['_route' => 'app_ui_viewseriesfromhash', '_controller' => 'App\\Controller\\UIController::viewSeriesFromHash'], ['hash'], null, null, false, true, null]],
        398 => [[['_route' => 'app_ui_viewoptout', '_controller' => 'App\\Controller\\UIController::viewOptOut'], ['hash'], null, null, false, true, null]],
        423 => [[['_route' => 'app_ui_getmail', '_controller' => 'App\\Controller\\UIController::getMail'], ['hash'], null, null, false, true, null]],
        453 => [[['_route' => 'app_ui_getseriesmail', '_controller' => 'App\\Controller\\UIController::getSeriesMail'], ['hash'], null, null, false, true, null]],
        484 => [[['_route' => 'app_ui_getmailsubjectforresults', '_controller' => 'App\\Controller\\UIController::getMailSubjectForResults'], ['hash'], null, null, false, true, null]],
        514 => [[['_route' => 'app_ui_getmailbodyforresults', '_controller' => 'App\\Controller\\UIController::getMailBodyForResults'], ['hash'], null, null, false, true, null]],
        553 => [[['_route' => 'app_ui_surveydownloadfromhashfortutors', '_controller' => 'App\\Controller\\UIController::surveyDownloadFromHashForTutors'], ['hash'], null, null, false, true, null]],
        570 => [[['_route' => 'app_ui_surveydownloadfromhash', '_controller' => 'App\\Controller\\UIController::surveyDownloadFromHash'], ['hash'], null, null, false, true, null]],
        610 => [
            [['_route' => '_preview_error', '_controller' => 'error_controller::preview', '_format' => 'html'], ['code', '_format'], null, null, false, true, null],
            [['_route' => '_twig_error_test', '_controller' => 'twig.controller.preview_error::previewErrorPageAction', '_format' => 'html'], ['code', '_format'], null, null, false, true, null],
            [null, null, null, null, false, false, 0],
        ],
    ],
    null, // $checkCondition
];
