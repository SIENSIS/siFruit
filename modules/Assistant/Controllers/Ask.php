<?php

declare(strict_types=1);

namespace Modules\Assistant\Controllers;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Modules\Assistant\Services\Answerer;
use Modules\Core\Controllers\FrontendController;
use Modules\Core\Security\RateLimiter;

/**
 * The chat assistant's endpoint, `POST assistant/ask` (D-51-01; docs/assistant.md): one question
 * (field `q`, form or JSON, at most 300 characters) → a JSON answer in the language of the URL
 * (`/es/assistant/ask`) for this visitor. CSRF (the global filter), at most 20 questions per
 * minute per IP address and per session (429 with Retry-After), 404 while `Assistant.enabled` is
 * off (and while the module is disabled, by the `moduleenabled` filter). Never cached; nothing is
 * stored unless `Assistant.logUnanswered` is on (D-51-02).
 *
 * Without JavaScript (a plain form post) the visitor is sent to the search results instead.
 */
final class Ask extends FrontendController
{
    public const PER_MINUTE = 20;

    public function ask(): ResponseInterface|RedirectResponse
    {
        if (! setting('Assistant.enabled')) {
            throw PageNotFoundException::forPageNotFound();
        }

        $request = $this->request;
        \assert($request instanceof IncomingRequest);

        $wait = $this->throttle($request);

        if ($wait > 0) {
            security_log('Assistant', 'throttled', ['summary' => '/assistant/ask']);

            return $this->json(['type' => 'error', 'message' => lang('Assistant.bubble.tooMany')], 429)->setHeader('Retry-After', (string) $wait);
        }

        $question = $this->question($request);

        if ($question === null || trim($question) === '') {
            return $this->json(['type' => 'error', 'message' => lang('Assistant.bubble.tooShort')], 422);
        }

        if (mb_strlen($question, 'UTF-8') > Answerer::MAX_LENGTH) {
            return $this->json(['type' => 'error', 'message' => lang('Assistant.bubble.tooLong', [Answerer::MAX_LENGTH])], 422);
        }

        if (! $this->wantsJson($request)) {
            return redirect()->to(site_url('search') . '?q=' . rawurlencode($question));
        }

        $user = auth()->loggedIn() ? auth()->user() : null;

        return $this->json((new Answerer())->answer($question, $request->getLocale(), $user));
    }

    /**
     * Counts the question per IP and per session; returns the seconds to wait (0: allowed).
     */
    private function throttle(IncomingRequest $request): int
    {
        $limiter = new RateLimiter();
        $wait    = $limiter->attemptCustom('assistantAsk', 'ip:' . $request->getIPAddress(), self::PER_MINUTE, MINUTE);
        $session = session_id();

        if ($session !== false && $session !== '') {
            $wait = max($wait, $limiter->attemptCustom('assistantAsk', 'session:' . hash('sha256', $session), self::PER_MINUTE, MINUTE));
        }

        return $wait;
    }

    private function question(IncomingRequest $request): ?string
    {
        if (str_contains($request->getHeaderLine('Content-Type'), 'application/json')) {
            try {
                $data = $request->getJSON(true);
            } catch (\Throwable) {
                return null;
            }

            $value = is_array($data) ? ($data['q'] ?? null) : null;
        } else {
            $value = $request->getPost('q');
        }

        return is_string($value) ? $value : null;
    }

    private function wantsJson(IncomingRequest $request): bool
    {
        return $request->isAJAX() || str_contains($request->getHeaderLine('Accept'), 'application/json');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data, int $status = 200): ResponseInterface
    {
        return $this->response->setStatusCode($status)
            ->setHeader('Cache-Control', 'private, no-store')
            ->setHeader('X-Robots-Tag', 'noindex')
            ->setJSON($data);
    }
}
