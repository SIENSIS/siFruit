# Tutorial 12 — A public form with email

A "request a to-do task" form on the public site: a visitor fills in their name, email, a title
and details, answers a captcha, and sends it. The request is stored, the users who review
requests are notified, and the visitor gets a confirmation email **in their language**. In the
back office, a reviewer accepts it (a private to-do task is created) or rejects it.

A public form is an open door: this tutorial is mostly about closing it properly (captcha, rate
limit, CSRF, validation, no data copied where it does not belong, a setting to turn it off).

Finished code:

| Piece | File |
|---|---|
| Routes | `modules/Tutorial/Config/Routes.php` (`tutorial/request` GET and POST, `tutorial/request/captcha`) |
| Controller | `modules/Tutorial/Controllers/RequestForm.php` (extends `FrontendController`) |
| View | `modules/Tutorial/Views/public/request.php` |
| Storage, notification, email | `modules/Tutorial/Services/TaskRequests.php` |
| Email | `modules/Tutorial/Views/emails/request_received.php` |
| Notification type | `modules/Tutorial/Notifications/TaskRequested.php` |
| Back office | `modules/Tutorial/Controllers/Requests.php` (resource `tutorial-requests`, operations `accept`, `reject`) |
| Table | `tutorial_task_requests` (`Database/Migrations/2026-11-22-100006_CreateTutorialTaskRequests.php`) |
| Setting | `Tutorial.publicRequests` (tutorial 8) |

## 1. Routes: CSRF and a rate limit

```php
$routes->get('request', 'RequestForm::show', ['as' => 'tutorial-request']);
$routes->post('request', 'RequestForm::send', ['filter' => 'ratelimit:forms']);
$routes->get('request/captcha', 'RequestForm::captcha');
```

CSRF is global for every POST outside the API. `ratelimit:forms` (setting `Core.rateLimitForms`)
answers 429 with `Retry-After` when one IP sends too many requests
([`security.md`](../security.md)). While `Tutorial.publicRequests` is off, every one of these URLs is
a 404 (`ensureOpen()` in the controller).

## 2. The form: always a captcha

The form reuses Auth's local captcha (an arithmetic image, no external service), required here
**whatever `Auth.captcha` says** for the login, because the form is anonymous. The image is
served by the module at `tutorial/request/captcha` (no-store) and the view uses the markup of
Auth's captcha partial with the `auth/captcha` component (reload button). The view escapes every
old value, marks invalid fields with `is-invalid` and `aria-describedby`, and has no inline
script or style.

## 3. Sending: captcha first, then validation

```php
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

    (new TaskRequests())->submit($data, service('request')->getLocale());

    return redirect()->to(site_url('tutorial/request'))->with('success', lang('Tutorial.form.sent'));
}
```

- Only the known fields are read (never `getPost()` wholesale into an insert); arrays are refused.
- The rules live next to the service that stores the data:

  ```php
  public const RULES = [
      'name'    => 'required|max_length[120]',
      'email'   => 'required|valid_email|max_length[190]',
      'title'   => 'required|min_length[3]|max_length[190]',
      'details' => 'permit_empty|max_length[5000]',
  ];
  ```

- Post/redirect/get: a successful send redirects with a flash message, so reloading does not
  send it twice.

## 4. Storing, notifying, confirming

`TaskRequests::submit()` stores the request with the visitor's language, notifies the reviewers
and queues the confirmation:

```php
notifyPermission('tutorial-requests.list', new TaskRequested(['id' => $id, 'title' => trim($data['title']), 'name' => trim($data['name'])]));
$this->confirm(trim($data['email']), trim($data['name']), trim($data['title']), $locale);

activity_log('Tutorial', 'request_received', [
    'resource'  => 'tutorial-requests',
    'record_id' => $id,
    'summary'   => trim($data['title']),
]);
```

- **Notify by permission**, not by group: whoever may list requests is told
  (`notifyPermission()`). The notification carries the title and name only, never the visitor's
  email.
- **The confirmation email** is rendered in the visitor's language with
  `RecipientLocale::using($locale, …)` and queued with the Core's `MailQueue` (sent by the
  scheduler, retried on failure): never send email inline from a request.

  ```php
  [$subject, $body] = RecipientLocale::using($locale, static function () use ($name, $title, $locale): array {
      $subject = lang('Tutorial.email.subject', [site_name()]);

      return [$subject, view('Modules\Tutorial\Views\emails\request_received', [
          'subject' => $subject, 'name' => $name, 'title' => $title, 'site' => site_name(), 'locale' => $locale,
      ], ['debug' => false])];
  });

  $this->mail->push($email, $subject, $body);
  ```

- The language is one of the site's available locales (`Locales::isAvailable()`), else the
  default.
- The email view escapes the visitor's text like any other view: it is untrusted input.

## 5. Reviewing in the back office

`Controllers/Requests.php` is a read-only CRUD (`except` `add`, `edit`, `import`, trash) with two
custom operations declared as `extra` permissions (`tutorial-requests.accept`, `.reject`).
`accept` creates a **private** to-do task in a transaction and opens it for editing; both only
act on requests still `new`, so a double click or a forged second POST changes nothing.

## Try it

Open `/tutorial/request` in a private window, send a request with a wrong captcha (refused, the
other values kept), then with the right one: "Thank you! We have received your request…". As `admin`: the bell
shows "Request: <title>", `backend/tutorial/requests` lists it, **Accept** creates the to-do
task. The confirmation email is in the mail queue (`mail_queue`; sent by `schedule:run` when
`email.*` is configured). In Settings › Tutorial module, turn off "Public request form": `/tutorial/request` is
a 404 and the public page hides its button.

## Tests

`modules/Tutorial/Tests/PublicSiteTest.php` (the helper `challenge(5)` stores a known captcha answer
in the session):

- `testTheRequestFormNeedsTheRightCaptcha`
- `testTheCaptchaImageIsServedEvenWithTheLoginCaptchaOff`
- `testAValidRequestIsStoredNotifiedAndConfirmedByEmail` (only reviewers notified, the email
  queued, the activity entry)
- `testTheConfirmationEmailIsInTheVisitorsLanguage` (`Accept-Language: es` → Spanish subject and
  `lang="es"`)
- `testInvalidRequestsAreRefusedWithTheirErrors`
- `testTheFormIsGoneWhenTheSettingIsOff`
- `testTheFormIsRateLimited` (429)
- `testAcceptingARequestCreatesAPrivateToDoTask`, `testRequestsCannotBeAcceptedWithoutThePermission`

```bash
vendor/bin/phpunit modules/Tutorial/Tests/PublicSiteTest.php
```

Next: [tutorial 13](13-releasing-a-version.md), releasing a new version of the module.
