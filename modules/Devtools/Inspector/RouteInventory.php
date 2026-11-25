<?php

declare(strict_types=1);

namespace Modules\Devtools\Inspector;

use CodeIgniter\Router\RouteCollection;
use CodeIgniter\Router\Router;
use Config\Filters;
use Modules\Core\Permissions\RouteSecurityAuditor;
use Modules\Core\Routing\BackendPath;

/**
 * Every route of the application from the router (Devtools' routes explorer and module
 * inspector, session 53): method, path, name, handler, filters and module, with a problem when a
 * back-office route has no `can:` filter, an API route has no kpapy filter (and is not declared
 * public), or a route that writes skips the CSRF filter. Read only.
 */
final class RouteInventory
{
    public const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(private readonly ?RouteCollection $routes = null)
    {
    }

    /**
     * @return list<array{method: string, path: string, name: string, handler: string, filters: list<string>, module: string, problem: string}>
     */
    public function all(): array
    {
        $routes  = $this->routes ?? service('routes')->loadRoutes();
        $auditor = new RouteSecurityAuditor();
        $issues  = [];

        foreach ($auditor->audit($routes) as $line) {
            [$key, $problem]             = explode(': ', $line, 2) + [1 => ''];
            $issues[$key][]              = $problem;
        }

        $out = [];

        foreach (Router::HTTP_METHODS as $verb) {
            foreach ($routes->getRoutes($verb) as $from => $to) {
                $from     = (string) $from;
                $handler  = is_string($to) ? $to : ($to instanceof \Closure ? '{closure}' : get_debug_type($to));
                $filters  = $routes->getFiltersForRoute($from, $verb);
                $options  = $routes->getRoutesOptions($from, $verb);
                $problems = $issues[$verb . ' ' . ltrim($from, '/')] ?? [];

                if (in_array($verb, self::WRITE_METHODS, true) && ! $this->csrfCovers(ltrim($from, '/'))) {
                    $problems[] = 'writes without the csrf filter';
                }

                $out[] = [
                    'method'  => $verb,
                    'path'    => '/' . ltrim($from, '/'),
                    'name'    => isset($options['as']) && is_string($options['as']) ? $options['as'] : '',
                    'handler' => $handler,
                    'filters' => $filters,
                    'module'  => self::moduleOf($handler, $from),
                    'problem' => implode('; ', $problems),
                ];
            }
        }

        usort($out, static fn (array $a, array $b): int => [$a['path'], $a['method']] <=> [$b['path'], $b['method']]);

        return $out;
    }

    /**
     * Whether the global `csrf` filter runs for a path: it is listed in `$globals['before']` and
     * the path is not in its `except` list. The token-only API (`api/*`, kpapy) is never flagged.
     */
    public function csrfCovers(string $path): bool
    {
        if ($path === 'api' || str_starts_with($path, 'api/')) {
            return true;
        }

        $globals = config(Filters::class)->globals['before'];
        $csrf    = $globals['csrf'] ?? (in_array('csrf', $globals, true) ? [] : null);

        if ($csrf === null) {
            return false;
        }

        foreach ((array) ($csrf['except'] ?? []) as $pattern) {
            $regex = '#^' . str_replace('\*', '.*', preg_quote(trim((string) $pattern, '/'), '#')) . '$#';

            if (preg_match($regex, $path) === 1) {
                return false;
            }
        }

        return true;
    }

    public static function moduleOf(string $handler, string $path = ''): string
    {
        if (preg_match('/^\\\\?Modules\\\\([A-Za-z0-9_]+)\\\\/', $handler, $m) === 1) {
            return $m[1];
        }

        if (preg_match('/^\\\\?(Kpapy|Kpcrudy|CodeIgniter)\\\\/', $handler, $m) === 1) {
            return $m[1];
        }

        return BackendPath::contains(ltrim($path, '/')) ? 'App' : (str_starts_with($handler, '\\App\\') || str_starts_with($handler, 'App\\') ? 'App' : '');
    }
}
