<?php

declare(strict_types=1);

namespace Modules\Auth\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Shield\Authentication\Authenticators\Session;
use Modules\Auth\Services\Impersonation;
use Modules\Core\Routing\BackendPath;

/**
 * `forcereset`: a user whose password must be changed (Shield's forced reset) only reaches the
 * password-change screen of the back office until it is done. Unlike Shield's `force-reset`
 * filter it can cover the back office without redirecting the password screen to itself.
 *
 * Not applied while a superadmin impersonates the user: the password change is refused during an
 * impersonation, so the forced change could never be done and every page would bounce between
 * the password screen and the profile (session 58). The user still has to change it themselves.
 */
final class ForcePasswordReset implements FilterInterface
{
    /** Paths written with the canonical prefix (`BackendPath::store()`). */
    public const ALLOWED = ['backend/profile/password'];

    /**
     * @param list<string>|null $arguments
     *
     * @return ResponseInterface|null
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        if (! $request instanceof IncomingRequest || Impersonation::active()) {
            return null;
        }

        /** @var Session $authenticator */
        $authenticator = auth('session')->getAuthenticator();

        if (! $authenticator->loggedIn() || ! $authenticator->getUser()?->requiresPasswordReset()) {
            return null;
        }

        if (in_array(BackendPath::store(trim($request->getPath(), '/')), self::ALLOWED, true)) {
            return null;
        }

        return redirect()->to(config('Auth')->forcePasswordResetRedirect())->with('warning', lang('Auth.msg.mustChangePassword'));
    }

    /**
     * @param list<string>|null $arguments
     *
     * @return ResponseInterface|null
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
