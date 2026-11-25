<?php

declare(strict_types=1);

use CodeIgniter\Router\RouteCollection;
use Kpapy\Resource;
use Modules\Core\Crud\CrudFactory;

/**
 * Routes of the Tutorial module (discovered by CodeIgniter; never queries the database). A
 * disabled module answers 404 (the global `moduleenabled` filter, controller namespace).
 *
 * Back office (`backend` chain + `can:`): the CRUDs `tutorial-tasks`, `tutorial-projects` and
 * `tutorial-requests` (registered before the board's `backend/tutorial/…`), the board `tutorial-board`
 * (`backend/tutorial`, its JSON data and the move endpoint). Public site: `tutorial` (public to-do
 * tasks), `tutorial/project/{slug}`, the request form `tutorial/request` (captcha, `ratelimit:forms`).
 * API: `api/v1/tutorial/tasks`, `api/v1/tutorial/projects` (kpapy filter).
 *
 * @var RouteCollection $routes
 */
CrudFactory::routes($routes, 'tutorial-tasks', backend_path('tutorial/tasks'), '\Modules\Tutorial\Controllers\Tasks::index');
CrudFactory::routes($routes, 'tutorial-projects', backend_path('tutorial/projects'), '\Modules\Tutorial\Controllers\Projects::index');
CrudFactory::routes($routes, 'tutorial-requests', backend_path('tutorial/requests'), '\Modules\Tutorial\Controllers\Requests::index');

$routes->group(backend_path('tutorial'), ['namespace' => 'Modules\Tutorial\Controllers'], static function (RouteCollection $routes): void {
    $board = ['filter' => 'can:tutorial-board.view'];

    $routes->get('/', 'Board::index', $board + ['as' => 'tutorial-board']);
    $routes->get('board/data', 'Board::data', $board);
    $routes->post('board/(:segment)/move', 'Board::move/$1', $board);
});

$routes->group('tutorial', ['namespace' => 'Modules\Tutorial\Controllers'], static function (RouteCollection $routes): void {
    $routes->get('/', 'PublicTasks::index', ['as' => 'tutorial-public']);
    $routes->get('project/(:segment)', 'PublicTasks::project/$1');
    $routes->get('request', 'RequestForm::show', ['as' => 'tutorial-request']);
    $routes->post('request', 'RequestForm::send', ['filter' => 'ratelimit:forms']);
    $routes->get('request/captcha', 'RequestForm::captcha');
});

Resource::routes($routes, 'api/v1/tutorial/tasks', '\Modules\Tutorial\Controllers\Api\Tasks::index', ['filter' => 'kpapy']);
Resource::routes($routes, 'api/v1/tutorial/projects', '\Modules\Tutorial\Controllers\Api\Projects::index', ['filter' => 'kpapy']);
