<?php

declare(strict_types=1);

namespace Modules\Tutorial\Tests;

use Modules\Auth\Services\Captcha;
use Modules\Tutorial\Menu\ProjectLink;
use Modules\Tutorial\Notifications\TaskRequested;

/**
 * The public site (tutorials 5 and 12): the public page with only public to-do tasks, the menu
 * link type, and the request form with its captcha, validation, rate limit, notification and
 * confirmation email.
 *
 * @internal
 */
final class PublicSiteTest extends TutorialTestCase
{
    public function testThePublicPageShowsOnlyPublicToDoTasks(): void
    {
        $web    = $this->project('Nou web');
        $hidden = $this->project('Arxivat', ['active' => 0]);
        $this->task('Pública del web', ['visibility' => 'public', 'project_id' => $web, 'labels' => '["design"]']);
        $this->task('Privada del web', ['project_id' => $web]);
        $this->task('Pública d’un projecte inactiu', ['visibility' => 'public', 'project_id' => $hidden]);
        $this->task('Pública a la paperera', ['visibility' => 'public', 'deleted_at' => date('Y-m-d H:i:s')]);
        $this->task('Pública sense projecte', ['visibility' => 'public', 'status' => 'done']);

        $page = $this->body('GET', 'tutorial');
        $this->assertStringContainsString('Pública del web', $page);
        $this->assertStringContainsString('Pública sense projecte', $page);
        $this->assertStringContainsString(lang('Tutorial.labels.design'), $page);
        $this->assertStringNotContainsString('Privada del web', $page);
        $this->assertStringNotContainsString('projecte inactiu', $page);
        $this->assertStringNotContainsString('paperera', $page);
        $this->assertStringContainsString('<link rel="canonical" href="' . site_url('tutorial') . '"', $page);
        $this->assertStringContainsString('<meta name="description"', $page);

        $project = $this->body('GET', 'tutorial/project/nou-web');
        $this->assertStringContainsString('Pública del web', $project);
        $this->assertStringNotContainsString('Pública sense projecte', $project);

        $this->assertNotFound('GET', 'tutorial/project/arxivat');
        $this->assertNotFound('GET', 'tutorial/project/no-existeix');
    }

    public function testTheMenuLinkTypeOffersTheActiveProjects(): void
    {
        $this->project('Nou web');
        $this->project('Arxivat', ['active' => 0]);
        $link = new ProjectLink();

        $this->assertSame('tutorial.project', $link->key());
        $this->assertSame(['*', 'nou-web'], array_keys($link->options()));
        $this->assertSame(site_url('tutorial'), $link->url('*'));
        $this->assertSame(site_url('tutorial/project/nou-web'), $link->url('nou-web'));
        $this->assertNull($link->url('arxivat'), 'An inactive project hides the menu item.');
    }

    public function testTheRequestFormNeedsTheRightCaptcha(): void
    {
        $this->body('GET', 'tutorial/request');
        $this->assertStringContainsString('tutorial/request/captcha', $this->body('GET', 'tutorial/request'));

        // No captcha, a wrong one, and a right one used twice: refused, nothing stored.
        $this->send('POST', 'tutorial/request', $this->request())->assertOK();
        $this->challenge(7);
        $this->send('POST', 'tutorial/request', $this->request(['captcha' => '8']))->assertOK();
        $this->assertSame(0, db_connect()->table('tutorial_task_requests')->countAllResults());
        $this->assertStringContainsString(lang('Tutorial.form.captchaWrong'), $this->body('POST', 'tutorial/request', $this->request(['captcha' => '7'])), 'Single use: the wrong answer removed the challenge.');
        $this->assertSame(0, db_connect()->table('tutorial_task_requests')->countAllResults());
    }

    public function testTheCaptchaImageIsServedEvenWithTheLoginCaptchaOff(): void
    {
        setting()->set('Auth.captcha', false);
        $response = $this->send('GET', 'tutorial/request/captcha');
        $response->assertOK();
        $this->assertSame('image/png', $response->response()->getHeaderLine('Content-Type'));
        $this->assertIsArray($_SESSION[Captcha::SESSION_KEY] ?? null, 'A new challenge is stored in the session.');
    }

    public function testAValidRequestIsStoredNotifiedAndConfirmedByEmail(): void
    {
        $boss = $this->createUser('boss@example.com', 'admin');
        $pau  = $this->createUser('pau@example.com', 'user');

        $this->challenge(5);
        $this->send('POST', 'en/tutorial/request', $this->request(['captcha' => '5']))->assertRedirectTo(site_url('en/tutorial/request'));

        $row = db_connect()->table('tutorial_task_requests')->get()->getRowArray();
        $this->assertIsArray($row);
        $this->assertSame('Afegir el calendari', $row['title']);
        $this->assertSame('new', $row['status']);
        $this->assertSame('en', $row['locale'], 'The language of the page (/en/…).');

        $this->assertSame(1, $this->notificationsOf((int) $boss->id, TaskRequested::class), 'Reviewers (tutorial-requests.list) are notified.');
        $this->assertSame(0, $this->notificationsOf((int) $pau->id, TaskRequested::class));

        $mail = db_connect()->table('mail_queue')->where('to_email', 'marta@example.com')->get()->getRowArray();
        $this->assertIsArray($mail, 'A confirmation email is queued.');
        $this->assertSame(lang('Tutorial.email.subject', [site_name()]), $mail['subject']);
        $this->assertStringContainsString('Afegir el calendari', (string) $mail['body']);
        $this->assertSame(1, db_connect()->table('activity_log')->where('action', 'request_received')->countAllResults());
    }

    public function testTheConfirmationEmailIsInTheVisitorsLanguage(): void
    {
        $this->challenge(5);
        $this->send('POST', 'es/tutorial/request', $this->request(['captcha' => '5']));
        $row = db_connect()->table('tutorial_task_requests')->get()->getRowArray();
        $this->assertIsArray($row);
        $this->assertSame('es', $row['locale']);

        $mail = db_connect()->table('mail_queue')->get()->getRowArray();
        $this->assertSame(lang('Tutorial.email.subject', [site_name()], 'es'), $mail['subject']);
        $this->assertStringContainsString('lang="es"', (string) $mail['body']);
    }

    public function testInvalidRequestsAreRefusedWithTheirErrors(): void
    {
        $this->challenge(5);
        $page = $this->body('POST', 'tutorial/request', $this->request(['captcha' => '5', 'email' => 'no-és-un-correu', 'title' => '']));

        $this->assertStringContainsString(lang('Tutorial.form.errors'), $page);
        $this->assertStringContainsString('is-invalid', $page);
        $this->assertSame(0, db_connect()->table('tutorial_task_requests')->countAllResults());
    }

    public function testTheFormIsGoneWhenTheSettingIsOff(): void
    {
        setting()->set('Tutorial.publicRequests', false);

        $this->assertNotFound('GET', 'tutorial/request');
        $this->assertNotFound('GET', 'tutorial/request/captcha');
        $this->challenge(5);
        $this->assertNotFound('POST', 'tutorial/request', $this->request(['captcha' => '5']));
        $this->assertStringNotContainsString('tutorial/request', $this->body('GET', 'tutorial'));
        $this->assertSame(0, db_connect()->table('tutorial_task_requests')->countAllResults());

        setting()->forget('Tutorial.publicRequests');
    }

    public function testTheFormIsRateLimited(): void
    {
        $this->assertContains('ratelimit:forms', $this->routeFilters('POST', 'tutorial/request'));
    }

    public function testAcceptingARequestCreatesAPrivateToDoTask(): void
    {
        $this->createUser('boss@example.com', 'admin');
        $this->login('boss@example.com');
        db_connect()->table('tutorial_task_requests')->insert(['name' => 'Marta', 'email' => 'marta@example.com', 'title' => 'Una idea', 'details' => 'Detalls', 'locale' => 'ca', 'status' => 'new', 'created_at' => date('Y-m-d H:i:s')]);
        $id   = (int) db_connect()->insertID();
        $code = \Kpcrudy\Crud::maskId($id, 'tutorial_task_requests');

        $this->send('POST', 'backend/tutorial/requests/accept/' . $code)->assertRedirect();

        $request = db_connect()->table('tutorial_task_requests')->where('id', $id)->get()->getRowArray();
        $this->assertSame('accepted', $request['status']);
        $task = $this->taskRow((int) $request['task_id']);
        $this->assertSame('Una idea', $task['title']);
        $this->assertSame('private', $task['visibility']);

        // Already handled: nothing more happens.
        $this->send('POST', 'backend/tutorial/requests/reject/' . $code)->assertRedirect();
        $this->assertSame('accepted', db_connect()->table('tutorial_task_requests')->where('id', $id)->get()->getRowArray()['status']);
        $this->assertSame(1, db_connect()->table('tutorial_tasks')->countAllResults());
    }

    public function testRequestsCannotBeAcceptedWithoutThePermission(): void
    {
        $this->loginWith('tutorial-requests.list', 'tutorial-requests.show');
        db_connect()->table('tutorial_task_requests')->insert(['name' => 'Marta', 'email' => 'marta@example.com', 'title' => 'Una idea', 'locale' => 'ca', 'status' => 'new', 'created_at' => date('Y-m-d H:i:s')]);
        $code = \Kpcrudy\Crud::maskId((int) db_connect()->insertID(), 'tutorial_task_requests');
        $list = 'backend/tutorial/requests';

        $this->assertCrudRefused($this->send('POST', $list . '/accept/' . $code), $list, 'accept');
        $this->assertCrudRefused($this->send('POST', $list . '/reject/' . $code), $list, 'reject');
        $this->assertSame('new', db_connect()->table('tutorial_task_requests')->get()->getRowArray()['status']);
        $this->assertSame(0, db_connect()->table('tutorial_tasks')->countAllResults());
    }

    /**
     * A captcha challenge whose answer is `$answer` in the session.
     */
    private function challenge(int $answer): void
    {
        $_SESSION[Captcha::SESSION_KEY] = ['key' => 'test-key', 'hash' => hash_hmac('sha256', (string) $answer, 'test-key')];
    }

    /**
     * @param array<string, string> $values
     *
     * @return array<string, string>
     */
    private function request(array $values = []): array
    {
        return $values + ['name' => 'Marta Riera', 'email' => 'marta@example.com', 'title' => 'Afegir el calendari', 'details' => 'Al web públic.'];
    }

    /**
     * The filters of a route.
     *
     * @return list<string>
     */
    private function routeFilters(string $method, string $path): array
    {
        $routes = service('routes');
        $routes->loadRoutes();

        return $routes->getFiltersForRoute($path, $method);
    }
}
