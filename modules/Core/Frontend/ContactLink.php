<?php

declare(strict_types=1);

namespace Modules\Core\Frontend;

use Modules\Core\Registry\ModuleState;

/**
 * The address of the site's contact page, for "nothing found" messages (the search results page,
 * the chat assistant): the route named `contact` when one is defined and the module of its
 * controller is enabled (the Contact module, session 48), else null. No module is named here.
 */
final class ContactLink
{
    public static function url(): ?string
    {
        $routes = service('routes');
        $path   = $routes->reverseRoute('contact');

        if (! is_string($path)) {
            return null;
        }

        $handler = $routes->getRoutes('GET')[trim($path, '/')] ?? null;
        $module  = is_string($handler) ? ModuleState::moduleOf($handler) : null;

        if ($module === null || ! (new ModuleState())->isEnabled($module)) {
            return null;
        }

        return site_url(trim($path, '/'));
    }
}
