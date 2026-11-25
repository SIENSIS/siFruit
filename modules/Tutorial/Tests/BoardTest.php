<?php

declare(strict_types=1);

namespace Modules\Tutorial\Tests;

/**
 * The board (tutorials 3 and 14): who sees which to-do tasks, the JSON endpoint of the component
 * `tutorial/board`, and who may move a card (forged requests included).
 *
 * @internal
 */
final class BoardTest extends TutorialTestCase
{
    public function testAUserSeesOnlyTheToDoTasksAssignedToThem(): void
    {
        $pau   = $this->createUser('pau@example.com', 'user');
        $other = $this->createUser('laia@example.com', 'user');
        $this->task('La meva', [], [(int) $pau->id]);
        $this->task('D’una altra persona', [], [(int) $other->id]);
        $this->login('pau@example.com');

        $page = $this->body('GET', 'backend/tutorial');
        $this->assertStringContainsString('La meva', $page);
        $this->assertStringNotContainsString('D’una altra persona', $page);
        $this->assertStringContainsString('data-component="tutorial/board"', $page);

        $data = json_decode((string) $this->send('GET', 'backend/tutorial/board/data')->response()->getBody(), true);
        $this->assertSame(['La meva'], array_column($data['columns']['todo'], 'title'));
        $this->assertTrue($data['columns']['todo'][0]['canMove'], 'An assignee may move their to-do task.');
    }

    public function testUsersWhoListToDoTasksSeeThemAll(): void
    {
        $this->loginWith('tutorial-board.view', 'tutorial-tasks.list');
        $this->task('Una');
        $this->task('Dues', ['status' => 'doing']);

        $data = json_decode((string) $this->send('GET', 'backend/tutorial/board/data')->response()->getBody(), true);
        $this->assertSame(['Una'], array_column($data['columns']['todo'], 'title'));
        $this->assertSame(['Dues'], array_column($data['columns']['doing'], 'title'));
        $this->assertFalse($data['columns']['todo'][0]['canMove'], 'Without tutorial-tasks.edit and not assigned: read only.');
    }

    public function testTheBoardNeedsItsPermission(): void
    {
        $this->send('GET', 'backend/tutorial')->assertRedirectTo(site_url('login'));

        $user = $this->loginWith();
        db_connect()->table('auth_groups_users')->where('user_id', $user->id)->delete();
        $this->login('ana@example.com');

        $this->send('GET', 'backend/tutorial')->assertStatus(403);
        $this->send('GET', 'backend/tutorial/board/data')->assertStatus(403);
    }

    public function testAnAssigneeMovesTheirToDoTaskAndTheMoveIsLogged(): void
    {
        $pau = $this->createUser('pau@example.com', 'user');
        $id  = $this->task('Moure-la', [], [(int) $pau->id]);
        $this->login('pau@example.com');

        $response = $this->withHeaders(['Accept' => 'application/json'])->send('POST', 'backend/tutorial/board/' . $this->taskCode($id) . '/move', ['status' => 'doing']);
        $response->assertOK();
        $this->assertSame('doing', json_decode((string) $response->response()->getBody(), true)['status']);
        $this->assertSame('doing', $this->taskRow($id)['status']);

        // The same endpoint without JavaScript: a form post and a redirect.
        $this->withHeaders(['Accept' => 'text/html'])->send('POST', 'backend/tutorial/board/' . $this->taskCode($id) . '/move', ['status' => 'done'])->assertRedirectTo(site_url('backend/tutorial'));
        $this->assertSame('done', $this->taskRow($id)['status']);
        $this->assertNotNull($this->taskRow($id)['completed_at']);
        $this->assertSame(2, db_connect()->table('activity_log')->where('module', 'Tutorial')->where('action', 'moved')->countAllResults());
    }

    public function testForgedMovesAreRefused(): void
    {
        $pau   = $this->createUser('pau@example.com', 'user');
        $other = $this->createUser('laia@example.com', 'user');
        $mine  = $this->task('La meva', [], [(int) $pau->id]);
        $their = $this->task('D’una altra', [], [(int) $other->id]);
        $this->login('pau@example.com');

        // Not visible to the user: 404, nothing changes.
        $this->assertNotFound('POST', 'backend/tutorial/board/' . $this->taskCode($their) . '/move', ['status' => 'done']);
        $this->assertNotFound('POST', 'backend/tutorial/board/not-a-code/move', ['status' => 'done']);
        $this->assertSame('todo', $this->taskRow($their)['status']);

        // An unknown status.
        $this->withHeaders(['Accept' => 'application/json'])->send('POST', 'backend/tutorial/board/' . $this->taskCode($mine) . '/move', ['status' => 'archived'])->assertStatus(422);
        $this->assertSame('todo', $this->taskRow($mine)['status']);

        // Visible (tutorial-tasks.list/show) but neither editor nor assignee: 403 and a security entry.
        $this->createUser('reader@example.com', 'user')->addPermission('tutorial-tasks.list', 'tutorial-tasks.show');
        $this->login('reader@example.com');
        $this->withHeaders(['Accept' => 'application/json'])->send('POST', 'backend/tutorial/board/' . $this->taskCode($their) . '/move', ['status' => 'done'])->assertStatus(403);
        $this->assertSame('todo', $this->taskRow($their)['status']);
        $this->assertSame(1, db_connect()->table('activity_log')->where('action', 'move_refused')->countAllResults());

        // A GET never moves.
        $this->assertNotFound('GET', 'backend/tutorial/board/' . $this->taskCode($their) . '/move');
    }

    public function testEditorsMoveAnyToDoTask(): void
    {
        $this->loginWith('tutorial-board.view', 'tutorial-tasks.list', 'tutorial-tasks.show', 'tutorial-tasks.edit');
        $id = $this->task('Qualsevol');

        $this->withHeaders(['Accept' => 'application/json'])->send('POST', 'backend/tutorial/board/' . $this->taskCode($id) . '/move', ['status' => 'doing'])->assertOK();
        $this->assertSame('doing', $this->taskRow($id)['status']);
    }
}
