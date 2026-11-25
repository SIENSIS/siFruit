<?php

declare(strict_types=1);

namespace Modules\Devtools\Inspector;

use CodeIgniter\Events\Events;
use CodeIgniter\Router\RouteCollection;
use Modules\Core\Config\ModuleManifest;
use Modules\Core\Models\ModuleModel;
use Modules\Core\Modules\DiscoveredModule;
use Modules\Core\Modules\ManifestValidator;
use Modules\Core\Modules\ModuleDiscovery;
use Modules\Core\Modules\ModuleMigrator;
use Modules\Core\Modules\SemVer;
use Modules\Core\Permissions\CatalogueBuilder;
use Modules\Core\Permissions\PermissionAudit;
use ReflectionFunction;
use ReflectionProperty;
use Throwable;

/**
 * The module inspector of Devtools (`backend/devtools/modules`, session 53, docs/devtools.md):
 * a module's manifest **as the Core resolves it** — its generated permissions and default
 * grants, routes from the router with their filters, menus, settings with their current values
 * (never a secret's), widgets, tasks, notifications, calendars, search sources, sections, API,
 * CSP, outbound hosts, backup tables, migrations, the events it listens to and its `CLAUDE.md`.
 * Read only: it uses the Core's services and never changes anything.
 */
final class ModuleInspector
{
    /** Manifest properties shown in their own sections; every other public array goes to "Other". */
    private const KNOWN = ['name', 'version', 'icon', 'description', 'author', 'requires', 'enabledByDefault', 'developmentOnly', 'repository', 'resources', 'defaultGrants', 'menu', 'settings', 'widgets', 'tasks', 'notifications', 'backup', 'calendars', 'calendarProviders', 'sectionTypes', 'pageModels', 'searchSources', 'fileReferences', 'api', 'csp', 'outboundHosts', 'menuLinks'];

    /** Setting names whose value is never shown (only "set / not set"). */
    private const SECRET = '/pass|secret|token|key|credential|private/i';

    /** @var array<string, DiscoveredModule>|null */
    private ?array $modules = null;

    public function __construct(private readonly ?ModuleDiscovery $discovery = null, private readonly ?RouteCollection $routes = null)
    {
    }

    /**
     * Every discovered module (enabled or not) with its registry state.
     *
     * @return list<array{name: string, version: string, installed: string|null, enabled: bool|null, developmentOnly: bool, valid: bool, description: string}>
     */
    public function list(): array
    {
        $registry = $this->registry();
        $out      = [];

        foreach ($this->modules() as $name => $module) {
            $out[] = [
                'name'            => $name,
                'version'         => $module->manifest !== null ? $module->manifest->version : '?',
                'installed'       => isset($registry[$name]) ? (string) $registry[$name]['version'] : null,
                'enabled'         => isset($registry[$name]) ? (bool) $registry[$name]['enabled'] : null,
                'developmentOnly' => $module->manifest !== null && $module->manifest->developmentOnly,
                'valid'           => $module->isValid(),
                'description'     => $module->manifest !== null ? self::text($module->manifest->description) : '',
            ];
        }

        return $out;
    }

    public function exists(string $name): bool
    {
        return isset($this->modules()[$name]);
    }

    /**
     * Everything about one module.
     *
     * @return array<string, mixed>|null
     */
    public function inspect(string $name): ?array
    {
        $module = $this->modules()[$name] ?? null;

        if ($module === null) {
            return null;
        }

        $manifest = $module->manifest;
        $registry = $this->registry()[$name] ?? null;

        if ($manifest === null) {
            return ['name' => $name, 'errors' => $module->errors, 'general' => [], 'problems' => []];
        }

        return [
            'name'          => $name,
            'errors'        => [...$module->errors, ...(new ManifestValidator())->validate($manifest, $module->path)],
            'problems'      => $this->problems($manifest),
            'general'       => [
                'version'          => $manifest->version,
                'namespace'        => $module->namespace,
                'path'             => self::relative($module->path),
                'description'      => self::text($manifest->description),
                'author'           => $manifest->author,
                'installed'        => $registry === null ? null : (string) $registry['version'],
                'enabled'          => $registry === null ? null : (bool) $registry['enabled'],
                'enabledByDefault' => $manifest->enabledByDefault,
                'developmentOnly'  => $manifest->developmentOnly,
            ],
            'requires'      => $this->requires($manifest),
            'permissions'   => $this->permissions($name, $manifest),
            'defaultGrants' => $manifest->defaultGrants,
            'routes'        => $this->routesOf($module),
            'menu'          => $manifest->menu,
            'settings'      => $this->settings($name, $manifest),
            'declarations'  => $this->declarations($manifest),
            'api'           => $manifest->api,
            'csp'           => $manifest->csp,
            'outboundHosts' => $manifest->outboundHosts,
            'backup'        => $manifest->backup,
            'migrations'    => $this->migrations($module, $registry !== null),
            'events'        => $this->events($module),
            'other'         => $this->other($manifest),
            'claude'        => is_file($module->path . '/CLAUDE.md') ? (string) file_get_contents($module->path . '/CLAUDE.md') : null,
        ];
    }

    /**
     * The routes of a module (the controller namespace of its handler), from the router.
     *
     * @return list<array{method: string, path: string, name: string, handler: string, filters: list<string>, problem: string}>
     */
    public function routesOf(DiscoveredModule $module): array
    {
        $prefix = '\\' . trim($module->namespace, '\\') . '\\';

        return array_values(array_filter(
            (new RouteInventory($this->routes))->all(),
            static fn (array $r): bool => str_starts_with('\\' . ltrim($r['handler'], '\\'), $prefix),
        ));
    }

    /**
     * @return array<string, DiscoveredModule>
     */
    private function modules(): array
    {
        return $this->modules ??= ($this->discovery ?? new ModuleDiscovery())->discover();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function registry(): array
    {
        try {
            return db_connect()->tableExists('modules') ? model(ModuleModel::class, false)->allByName() : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return list<array{module: string, constraint: string, version: string|null, met: bool}>
     */
    private function requires(ModuleManifest $manifest): array
    {
        $out = [];

        foreach ($manifest->requires as $module => $constraint) {
            $other   = $this->modules()[$module] ?? null;
            $version = $other?->manifest?->version;
            $out[]   = ['module' => (string) $module, 'constraint' => (string) $constraint, 'version' => $version, 'met' => $version !== null && SemVer::satisfies($version, (string) $constraint)];
        }

        return $out;
    }

    /**
     * The permissions the Core generates from the manifest, with the groups granted each.
     *
     * @return list<array{name: string, resource: string, operation: string, description: string, groups: list<string>}>
     */
    private function permissions(string $name, ModuleManifest $manifest): array
    {
        $out = [];

        foreach ((new CatalogueBuilder())->build([$name => $manifest]) as $permission => $entry) {
            $groups = [];

            foreach ($manifest->defaultGrants as $group => $grants) {
                foreach ($grants as $grant) {
                    if ($grant === $permission || (str_ends_with($grant, '.*') && str_starts_with($permission, substr($grant, 0, -1)))) {
                        $groups[] = (string) $group;

                        break;
                    }
                }
            }

            $out[] = ['name' => $permission, 'resource' => $entry['resource'], 'operation' => $entry['operation'], 'description' => self::text($entry['description']), 'groups' => $groups];
        }

        return $out;
    }

    /**
     * The settings with their type, group, default and current value; a secret's value is never
     * shown (`password` type or a name like a key, token or password).
     *
     * @return list<array{key: string, type: string, group: string, default: string, value: string, secret: bool}>
     */
    private function settings(string $module, ModuleManifest $manifest): array
    {
        $out = [];

        foreach ($manifest->settings as $key => $definition) {
            $type   = (string) ($definition['type'] ?? 'string');
            $secret = $type === 'password' || preg_match(self::SECRET, (string) $key) === 1;

            try {
                $value = setting($module . '.' . $key);
            } catch (Throwable) {
                $value = null;
            }

            $out[] = [
                'key'     => $module . '.' . $key,
                'type'    => $type,
                'group'   => (string) ($definition['group'] ?? ''),
                'default' => $secret ? '' : self::show($definition['default'] ?? null),
                'value'   => $secret ? lang(self::filled($value) ? 'Install.about.set' : 'Install.about.notSet') : self::show($value),
                'secret'  => $secret,
            ];
        }

        return $out;
    }

    /**
     * Widgets, scheduled tasks (with their schedule), notification types (with their channels),
     * calendars, calendar providers, search sources, section types and page models.
     *
     * @return array<string, list<array<string, string>>>
     */
    private function declarations(ModuleManifest $manifest): array
    {
        $out = [];

        foreach ($manifest->widgets as $class) {
            $out['widgets'][] = self::described((string) $class, static fn (object $w): array => method_exists($w, 'id') ? ['id' => (string) $w->id(), 'permission' => (string) ($w->permission() ?? '')] : []);
        }

        foreach ($manifest->tasks as $class) {
            $out['tasks'][] = self::described((string) $class, static fn (object $t): array => method_exists($t, 'schedule') ? ['schedule' => $t->schedule()->expression()] : []);
        }

        foreach ($manifest->notifications as $class) {
            $class                    = (string) $class;
            $out['notifications'][] = ['class' => $class] + (class_exists($class) && method_exists($class, 'defaultChannels') ? ['channels' => implode(', ', $class::defaultChannels()), 'mandatory' => $class::mandatory() ? 'yes' : 'no'] : ['error' => 'class not found']);
        }

        foreach ($manifest->calendars as $key => $definition) {
            $out['calendars'][] = ['key' => (string) $key, 'permission' => self::show($definition['permission'] ?? ''), 'policy' => self::show($definition['policy'] ?? '')];
        }

        foreach (['calendarProviders', 'sectionTypes', 'pageModels', 'fileReferences'] as $kind) {
            foreach ($manifest->{$kind} as $key => $class) {
                $out[$kind][] = ['key' => (string) $key, 'class' => (string) $class];
            }
        }

        foreach ($manifest->searchSources as $key => $definition) {
            $out['searchSources'][] = ['key' => (string) $key, 'class' => $definition];
        }

        return $out;
    }

    /**
     * Migrations of the module's namespace: run or pending.
     *
     * @return list<array{name: string, pending: bool}>
     */
    private function migrations(DiscoveredModule $module, bool $installed): array
    {
        $files = glob($module->path . '/Database/Migrations/*.php') ?: [];
        sort($files);

        try {
            $pending = $installed ? (new ModuleMigrator())->pending($module->namespace) : null;
        } catch (Throwable) {
            $pending = null;
        }

        return array_map(static fn (string $file): array => ['name' => basename($file, '.php'), 'pending' => $pending === null || in_array(basename($file, '.php'), $pending, true)], $files);
    }

    /**
     * The events the module listens to: the listeners registered from its folder (closures and
     * methods defined in its files), read from CodeIgniter's events registry.
     *
     * @return list<string>
     */
    private function events(DiscoveredModule $module): array
    {
        Events::initialize();
        $path = rtrim((string) realpath($module->path), '/') . '/';

        try {
            $listeners = (new ReflectionProperty(Events::class, 'listeners'))->getValue();
        } catch (Throwable) {
            return [];
        }

        $events = [];

        foreach (is_array($listeners) ? $listeners : [] as $event => $data) {
            foreach (is_array($data[2] ?? null) ? $data[2] : [] as $listener) {
                $file = self::fileOf($listener);

                if ($file !== null && str_starts_with($file, $path)) {
                    $events[] = (string) $event;

                    break;
                }
            }
        }

        sort($events);

        return array_values(array_unique($events));
    }

    /**
     * Public array properties of the manifest not shown in their own section.
     *
     * @return array<string, mixed>
     */
    private function other(ModuleManifest $manifest): array
    {
        $out = [];

        foreach (get_object_vars($manifest) as $key => $value) {
            if (! in_array($key, self::KNOWN, true) && $value !== [] && $value !== null && $value !== '') {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * What `permissions:check` reports about this module (run as a library call, read only):
     * lines naming the module, one of its permissions or one of its routes.
     *
     * @return list<string>
     */
    private function problems(ModuleManifest $manifest): array
    {
        try {
            $result = (new PermissionAudit(null, $this->routes))->run();
        } catch (Throwable $e) {
            return [$e->getMessage()];
        }

        $needles = [$manifest->name];

        foreach (array_keys($manifest->resources) as $resource) {
            $needles[] = $manifest->permissionPrefix((string) $resource) . '.';
        }

        $out = [];

        foreach ([...$result['errors'], ...$result['warnings']] as $line) {
            foreach ($needles as $needle) {
                if (str_contains($line, $needle)) {
                    $out[] = $line;

                    break;
                }
            }
        }

        return $out;
    }

    /**
     * @param callable(object): array<string, string> $describe
     *
     * @return array<string, string>
     */
    private static function described(string $class, callable $describe): array
    {
        if (! class_exists($class)) {
            return ['class' => $class, 'error' => 'class not found'];
        }

        try {
            return ['class' => $class] + $describe(new $class());
        } catch (Throwable $e) {
            return ['class' => $class, 'error' => $e->getMessage()];
        }
    }

    private static function fileOf(mixed $listener): ?string
    {
        try {
            if ($listener instanceof \Closure || (is_string($listener) && function_exists($listener))) {
                $file = (new ReflectionFunction($listener))->getFileName();
            } elseif (is_array($listener) && count($listener) === 2) {
                $file = (new \ReflectionMethod($listener[0], (string) $listener[1]))->getFileName();
            } elseif (is_string($listener) && str_contains($listener, '::')) {
                $file = (new \ReflectionMethod($listener))->getFileName();
            } else {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        return is_string($file) ? ((string) realpath($file) ?: $file) : null;
    }

    private static function text(string $value): string
    {
        return $value !== '' && preg_match('/^[A-Z][A-Za-z0-9]*\.[A-Za-z0-9_.]+$/', $value) === 1 ? lang($value) : $value;
    }

    private static function show(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        return is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function filled(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== [];
    }

    public static function relative(string $path): string
    {
        $root = rtrim(ROOTPATH, '/') . '/';

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : basename($path);
    }
}
