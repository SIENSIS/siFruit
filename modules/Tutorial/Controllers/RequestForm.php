<?php

declare(strict_types=1);

namespace Modules\Tutorial\Controllers;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Modules\Auth\Services\Captcha;
use Modules\Core\Controllers\FrontendController;
use Modules\Tutorial\Services\TaskRequests;

/**
 * The public form "request a to-do task" (tutorial 12), while the setting `Tutorial.publicRequests`
 * is on (otherwise every URL here is a 404):
 *
 * - `GET tutorial/request`: the form (name, email, title, details) with a **captcha that is always
 *   required**, whatever the setting `Auth.captcha` says (Auth's local arithmetic captcha,
 *   D-00-53; its image is served by this module at `GET tutorial/request/captcha`);
 * - `POST tutorial/request` (`ratelimit:forms`, CSRF): checks the captcha (single use) and the
 *   rules, stores the request, notifies the reviewers and queues the confirmation email
 *   (`TaskRequests::submit()`), then redirects back with a message (post/redirect/get).
 */
final class RequestForm extends FrontendController
{
    public function show(): string
    {
        $this->ensureOpen();

        return $this->form();
    }

    public function send(): RedirectResponse|string
    {
        $this->ensureOpen();

        $data = [];

        foreach (array_keys(TaskRequests::RULES) as $field) {
            $value        = $this->request->getPost($field);
            $data[$field] = is_string($value) ? trim($value) : '';
        }

        // The captcha first: it is single use, so it is checked once whatever else fails.
        if (! (new Captcha())->verify($this->request->getPost('captcha'))) {
            return $this->form($data, ['captcha' => lang('Tutorial.form.captchaWrong')]);
        }

        if (! $this->validateData($data, TaskRequests::RULES)) {
            return $this->form($data, $this->validator?->getErrors() ?? []);
        }

        /** @var array{name: string, email: string, title: string, details: string} $data */
        (new TaskRequests())->submit($data, service('request')->getLocale());

        return redirect()->to(site_url('tutorial/request'))->with('success', lang('Tutorial.form.sent'));
    }

    public function captcha(): ResponseInterface
    {
        $this->ensureOpen();

        return $this->response
            ->setHeader('Content-Type', 'image/png')
            ->setHeader('Cache-Control', 'no-store, max-age=0')
            ->setBody((new Captcha())->image());
    }

    /**
     * @param array<string, string> $old
     * @param array<string, string> $errors
     */
    private function form(array $old = [], array $errors = []): string
    {
        $this->meta([
            'description' => lang('Tutorial.form.intro'),
            'canonical'   => site_url('tutorial/request'),
            'robots'      => 'noindex',
        ]);

        return $this->render('Modules\Tutorial\Views\public\request', [
            'title'       => lang('Tutorial.form.title'),
            'old'         => $old,
            'errors'      => $errors,
            'breadcrumbs' => [['label' => lang('Tutorial.public.title'), 'url' => site_url('tutorial')], ['label' => lang('Tutorial.form.title')]],
        ]);
    }

    private function ensureOpen(): void
    {
        if (! (bool) setting('Tutorial.publicRequests')) {
            throw PageNotFoundException::forPageNotFound();
        }
    }
}
