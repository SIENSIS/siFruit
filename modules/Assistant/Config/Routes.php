<?php

declare(strict_types=1);

use CodeIgniter\Router\RouteCollection;
use Modules\Core\Crud\CrudFactory;

/**
 * Routes of the Assistant module (discovered by CodeIgniter; never queries the database). A
 * disabled module answers 404 (the global `moduleenabled` filter, controller namespace).
 *
 * Back office (`backend` chain + `can:`): the CRUD `assistant-faqs`, the unanswered questions
 * (`can:assistant-faqs.add`, D-51-02). Public: `POST assistant/ask` (the bubble's endpoint, CSRF,
 * rate-limited in the controller) and `faq` (the FAQ page, D-51-03), both with the language prefix.
 *
 * @var RouteCollection $routes
 */
CrudFactory::routes($routes, 'assistant-faqs', backend_path('assistant/faqs'), '\Modules\Assistant\Controllers\Faqs::index');

$routes->group(backend_path('assistant/unanswered'), ['namespace' => 'Modules\Assistant\Controllers'], static function (RouteCollection $routes): void {
    $can = ['filter' => 'can:assistant-faqs.add'];

    $routes->get('/', 'Unanswered::index', $can + ['as' => 'assistant-unanswered']);
    $routes->post('faq/(:num)', 'Unanswered::toFaq/$1', $can);
    $routes->post('delete/(:num)', 'Unanswered::delete/$1', $can);
});

$routes->post('assistant/ask', '\Modules\Assistant\Controllers\Ask::ask', ['as' => 'assistant-ask']);
$routes->get('faq', '\Modules\Assistant\Controllers\PublicFaq::index', ['as' => 'assistant-faq']);
