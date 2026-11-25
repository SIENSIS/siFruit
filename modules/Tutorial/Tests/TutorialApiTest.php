<?php

declare(strict_types=1);

namespace Modules\Tutorial\Tests;

use Kpcrudy\Crud;
use Modules\Api\Tests\ApiTestCase;
use Modules\Core\Modules\CacheInvalidator;
use Modules\Core\Modules\ModuleDiscovery;
use Modules\Core\Permissions\CrudPermissions;
use Modules\Core\Registry\DeclarationRegistry;
use Modules\Tutorial\Notifications\TaskAssigned;

/**
 * The API of the to-do tasks (tutorial 11): the same permissions as the CRUDs, token scopes,
 * masked ids shared with the back office, filters and includes, the hidden estimate, the custom
 * operation `complete`, translated projects, and the hooks (notifications, calendar).
 *
 * @internal
 */
final class TutorialApiTest extends ApiTestCase
{
    use TutorialFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        CrudPermissions::reset();

        // The declarations of every module (notification types, settings), as modules:sync stores them.
        $manifests = [];

        foreach ((new ModuleDiscovery())->discover() as $name => $module) {
            if ($module->isValid() && $module->manifest !== null) {
                $manifests[$name] = $module->manifest;
            }
        }

        $registry = new DeclarationRegistry();
        $registry->store($registry->build($manifests));
        (new CacheInvalidator())->invalidate(['modules']);
    }

    public function testATokenLimitedToListCanListButNotWrite(): void
    {
        $boss = $this->createUser('boss@example.com', 'admin');
        $id   = $this->task('Revisar', ['estimate_hours' => '3.00']);
        $list = $this->tokenFor($boss, ['tutorial-tasks.list']);

        $response = $this->api('GET', 'api/v1/tutorial/tasks', null, $list);
        $response->assertStatus(200);
        $item = $this->json($response)['data'][0];
        $this->assertSame(Crud::maskId($id, 'tutorial_tasks'), $item['id'], 'The same masked id as the back office.');

        $this->api('POST', 'api/v1/tutorial/tasks', ['title' => 'Nova'], $list)->assertStatus(403);
        $this->api('PATCH', 'api/v1/tutorial/tasks/' . $item['id'], ['title' => 'Canviada'], $list)->assertStatus(403);
        $this->api('DELETE', 'api/v1/tutorial/tasks/' . $item['id'], null, $list)->assertStatus(403);
        $this->api('POST', 'api/v1/tutorial/tasks/' . $item['id'] . '/complete', null, $list)->assertStatus(403);
        $this->assertSame('Revisar', $this->taskRow($id)['title']);
        $this->assertSame('todo', $this->taskRow($id)['status']);

        $this->api('GET', 'api/v1/tutorial/tasks')->assertStatus(401);
    }

    public function testTheUsersPermissionsAreTheCeiling(): void
    {
        $pau = $this->createUser('pau@example.com', 'user');
        $this->task('Revisar');

        $this->api('GET', 'api/v1/tutorial/tasks', null, $this->tokenFor($pau))->assertStatus(403);
        $this->api('GET', 'api/v1/tutorial/projects', null, $this->tokenFor($pau))->assertStatus(403);
    }

    public function testWritesFiltersIncludesAndTheCompleteOperation(): void
    {
        $boss    = $this->createUser('boss@example.com', 'admin');
        $laia    = $this->createUser('laia@example.com', 'editor');
        $project = $this->project('Nou web');
        $token   = $this->tokenFor($boss);

        $created = $this->api('POST', 'api/v1/tutorial/tasks', [
            'title'      => 'Des de l’API',
            'project_id' => Crud::maskId($project, 'tutorial_projects'),
            'labels'     => ['feature'],
            'assignees'  => [Crud::maskId((int) $laia->id, 'users')],
            'due_on'     => date('Y-m-d', strtotime('+4 days')),
        ], $token);
        $created->assertStatus(201);
        $code = $this->json($created)['data']['id'];
        $id   = (int) Crud::unmaskId($code, 'tutorial_tasks');

        $this->assertSame((int) $boss->id, (int) $this->taskRow($id)['created_by']);
        $this->assertSame(1, db_connect()->table('notifications')->where('user_id', $laia->id)->where('type', TaskAssigned::class)->countAllResults(), 'New assignees are notified from the API too.');
        $this->assertNotNull(db_connect()->table('events')->where('source_module', 'Tutorial')->where('source_key', 'task-' . $id)->get()->getRowArray(), 'Its due date is in the calendar.');

        $this->task('Una altra', ['status' => 'doing']);
        $filtered = $this->json($this->api('GET', 'api/v1/tutorial/tasks?filter[status]=todo&include=project', null, $token))['data'];
        $this->assertSame(['Des de l’API'], array_column($filtered, 'title'));
        $this->assertSame('nou-web', $filtered[0]['project']['slug']);
        $this->assertSame(['feature'], $filtered[0]['labels']);

        $this->assertNull($this->taskRow($id)['started_at'], 'Created as "to do": not started (tutorial 13).');

        $this->api('POST', 'api/v1/tutorial/tasks/' . $code . '/complete', null, $token)->assertStatus(200);
        $this->assertSame('done', $this->taskRow($id)['status']);
        $this->assertNotNull($this->taskRow($id)['completed_at']);
        $this->assertNotNull($this->taskRow($id)['started_at'], 'Completing also starts it.');

        $this->api('PATCH', 'api/v1/tutorial/tasks/' . $code, ['status' => 'todo', 'created_by' => 999], $token)->assertStatus(200);
        $this->assertNull($this->taskRow($id)['started_at'], 'Back to "to do": the dates are cleared, as in the CRUD.');
        $this->assertNull($this->taskRow($id)['completed_at']);
        $this->assertSame((int) $boss->id, (int) $this->taskRow($id)['created_by'], 'A field outside writeFields() is ignored.');
        $this->api('POST', 'api/v1/tutorial/tasks/' . $code . '/complete', null, $token)->assertStatus(200);
        $this->api('POST', 'api/v1/tutorial/tasks/' . $code . '/complete', null, $token)->assertStatus(409);

        // The complete scope alone is enough for the operation, not for edits.
        $other = $this->task('Tercera');
        $only  = $this->tokenFor($boss, ['tutorial-tasks.complete']);
        $this->api('POST', 'api/v1/tutorial/tasks/' . Crud::maskId($other, 'tutorial_tasks') . '/complete', null, $only)->assertStatus(200);
        $this->api('PATCH', 'api/v1/tutorial/tasks/' . Crud::maskId($other, 'tutorial_tasks'), ['title' => 'No'], $only)->assertStatus(403);
    }

    public function testTheEstimateFollowsItsPermission(): void
    {
        $editor = $this->createUser('laia@example.com', 'editor');
        $boss   = $this->createUser('boss@example.com', 'admin');
        $id     = $this->task('Amb estimació', ['estimate_hours' => '3.00']);
        $path   = 'api/v1/tutorial/tasks/' . Crud::maskId($id, 'tutorial_tasks');

        $this->assertArrayNotHasKey('estimate_hours', $this->json($this->api('GET', $path, null, $this->tokenFor($editor)))['data']);
        $this->api('PATCH', $path, ['estimate_hours' => '50'], $this->tokenFor($editor));
        $this->assertSame('3.00', number_format((float) $this->taskRow($id)['estimate_hours'], 2, '.', ''), 'A hidden field is never written.');

        $this->assertSame('3.00', $this->json($this->api('GET', $path, null, $this->tokenFor($boss)))['data']['estimate_hours']);
    }

    public function testProjectsInTheRequestedLanguage(): void
    {
        $boss = $this->createUser('boss@example.com', 'admin');
        $id   = $this->project('Nou web');
        db_connect()->table('tutorial_project_translations')->insert(['project_id' => $id, 'locale' => 'es', 'name' => 'Nueva web']);

        $data = $this->json($this->api('GET', 'api/v1/tutorial/projects/' . Crud::maskId($id, 'tutorial_projects') . '?lang=es', null, $this->tokenFor($boss)))['data'];
        $this->assertSame('Nueva web', $data['name']);

        $all = $this->json($this->api('GET', 'api/v1/tutorial/projects/' . Crud::maskId($id, 'tutorial_projects') . '?lang=*', null, $this->tokenFor($boss)))['data'];
        $this->assertSame('Nou web', $all['name']['ca']);
    }
}
