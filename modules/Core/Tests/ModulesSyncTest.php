<?php

declare(strict_types=1);

namespace Modules\Core\Tests;

use CodeIgniter\Events\Events;
use CodeIgniter\Router\RouteCollection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Modules as ModulesConfig;
use Config\Services;
use Modules\Core\Models\ModuleModel;
use Modules\Core\Modules\CacheInvalidator;
use Modules\Core\Modules\ModuleDiscovery;
use Modules\Core\Modules\ModuleGenerator;
use Modules\Core\Modules\ModuleOverview;
use Modules\Core\Modules\ModuleSynchronizer;
use Modules\Core\Modules\NamespaceFile;
use Modules\Core\Modules\RenamedModules;
use Modules\Core\Modules\SyncFailedException;
use Modules\Core\Registry\OpenApiDocument;

/**
 * modules:sync end to end on the test database, with the real Core and a generated module in a
 * temporary folder.
 *
 * @internal
 */
final class ModulesSyncTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use CleanDatabaseTrait;
    use TempModulesTrait;

    protected $migrate   = false;
    protected $refresh   = false;
    protected $namespace = null;

    private string $module = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dropAllTables();
        service('language')->setLocale('en');

        $this->module = $this->uniqueModuleName();
        (new ModuleGenerator($this->tempRoot()))->generate($this->module);
    }

    protected function tearDown(): void
    {
        $this->removeTempRoot();
        parent::tearDown();
    }

    private function synchronizer(): ModuleSynchronizer
    {
        return new ModuleSynchronizer(
            new ModuleDiscovery([ROOTPATH . 'modules', $this->tempRoot()]),
            new NamespaceFile($this->tempRoot() . '/ModuleNamespaces.php'),
            openApi: new OpenApiDocument($this->tempRoot() . '/api/openapi.json'),
        );
    }

    public function testFirstSyncInstallsSecondSyncChangesNothing(): void
    {
        $invalidated = 0;
        Events::on(CacheInvalidator::EVENT, static function () use (&$invalidated): void {
            $invalidated++;
        });

        $first = $this->synchronizer()->sync();

        $this->assertTrue($first->namespacesChanged);
        $this->assertSame(23, $first->migrations['Modules\Core']);
        $this->assertSame(1, $first->migrations['Modules\\' . $this->module]);
        $this->assertArrayHasKey('Core', $first->installed);
        $this->assertArrayHasKey($this->module, $first->installed);
        $this->assertContains($this->module, $first->seeded);
        $this->assertSame('Core', $first->order[0]);
        $this->assertTrue($this->db->tableExists('modules'));
        $this->assertTrue($this->db->tableExists(strtolower($this->module) . '_items'));
        $this->assertTrue($this->db->tableExists('users'), 'Shield migrations run first');
        $this->assertSame(1, $invalidated);
        $this->assertTrue($first->openApiChanged);
        $this->assertSame([], $first->openApiWarnings);
        $document = json_decode((string) file_get_contents($this->tempRoot() . '/api/openapi.json'), true);
        $this->assertIsArray($document);
        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertArrayHasKey('/api/v1/notifications', $document['paths'], 'The OpenAPI document of api/v1 (session 26).');
        $this->assertArrayHasKey('/api/v1/public/news', $document['paths']);

        $namespaces = require $this->tempRoot() . '/ModuleNamespaces.php';
        $this->assertIsArray($namespaces);
        $this->assertArrayHasKey('Modules\Core', $namespaces);
        $this->assertArrayHasKey('Modules\\' . $this->module, $namespaces);

        $registry = model(ModuleModel::class, false)->allByName();
        $this->assertSame('0.1.0', $registry[$this->module]['version']);
        $this->assertTrue($registry[$this->module]['enabled']);

        $second = $this->synchronizer()->sync();

        $this->assertFalse($second->hasChanges());
        $this->assertSame([], $second->lines());
        $this->assertSame(1, $invalidated, 'caches are not invalidated when nothing changed');
        $this->assertSame($registry, model(ModuleModel::class, false)->allByName());
    }

    public function testNewModuleRoutesAreDiscoveredAfterSync(): void
    {
        $this->synchronizer()->sync();

        $routes = new RouteCollection(Services::locator(), new ModulesConfig(), config('Routing'));
        $routes->loadRoutes();

        $this->assertArrayHasKey('backend/' . strtolower($this->module), $routes->getRoutes('GET'));
    }

    public function testVersionBumpIsRecordedAsUpgrade(): void
    {
        $this->synchronizer()->sync();

        $file = $this->tempRoot() . '/' . $this->module . '/Config/Module.php';
        file_put_contents($file, str_replace("'0.1.0'", "'0.2.0'", (string) file_get_contents($file)));

        // The manifest class is already loaded: check the registry update through a fresh class.
        $registry = model(ModuleModel::class, false);
        $row      = $registry->allByName()[$this->module];
        $registry->update($row['id'], ['version' => '0.0.9']);

        $report = $this->synchronizer()->sync();

        $this->assertSame([$this->module => ['0.0.9', '0.1.0']], $report->upgraded);
        $this->assertSame('0.1.0', $registry->allByName()[$this->module]['version']);
    }

    public function testARenamedBundledModuleLeftInTheRegistryNamesTheStepToFollow(): void
    {
        // An installation that enabled the tutorial module before session 52 updates without
        // running modules:uninstall Tasks first: the registry still has Tasks, its folder is gone.
        $this->synchronizer()->sync();
        $now = date('Y-m-d H:i:s');
        model(ModuleModel::class, false)->insert(['name' => 'Tasks', 'version' => '0.2.0', 'enabled' => 1, 'installed_at' => $now, 'updated_at' => $now]);
        model(ModuleModel::class, false)->insert(['name' => 'Gone', 'version' => '1.0.0', 'enabled' => 0, 'installed_at' => $now, 'updated_at' => $now]);

        $report = $this->synchronizer()->sync();
        $lines  = implode("\n", $report->lines());

        $this->assertEqualsCanonicalizing(['Tasks', 'Gone'], $report->missing);
        $this->assertSame('Tutorial', RenamedModules::newName('Tasks'));
        $this->assertStringContainsString('Tasks is registered but its folder no longer exists.', $lines);
        $this->assertStringContainsString('Tasks was renamed Tutorial in this release: run php spark modules:uninstall Tasks', $lines);
        $this->assertStringContainsString('php spark modules:enable Tutorial', $lines);
        $this->assertStringContainsString('Gone is registered but its folder no longer exists.', $lines);
        $this->assertStringNotContainsString('Gone was renamed', $lines, 'Only bundled modules renamed by a release get the hint.');
        $this->assertNull(RenamedModules::hint('Gone'));

        // The Modules screen shows the same step next to the missing folder.
        $rows = (new ModuleOverview(new ModuleDiscovery([ROOTPATH . 'modules', $this->tempRoot()])))->rows();
        $this->assertSame(ModuleOverview::STATUS_ERROR, $rows['Tasks']['status']);
        $this->assertSame(['Its folder no longer exists.', (string) RenamedModules::hint('Tasks')], $rows['Tasks']['errors']);
        $this->assertSame(['Its folder no longer exists.'], $rows['Gone']['errors']);
    }

    public function testInvalidManifestFailsAndChangesNothing(): void
    {
        $broken = $this->uniqueModuleName();
        (new ModuleGenerator($this->tempRoot()))->generate($broken);

        $file = $this->tempRoot() . '/' . $broken . '/Config/Module.php';
        file_put_contents($file, str_replace("'type' => 'page'", "'type' => 'pages'", (string) file_get_contents($file)));

        try {
            $this->synchronizer()->sync();
            $this->fail('An invalid manifest must stop the synchronisation');
        } catch (SyncFailedException $e) {
            $this->assertStringContainsString("{$broken}: \$resources['main']['type'] must be one of: crud, page", implode("\n", $e->errors));
        }

        $this->assertFalse($this->db->tableExists('modules'), 'nothing was migrated');
        $this->assertFileDoesNotExist($this->tempRoot() . '/ModuleNamespaces.php');
    }
}
