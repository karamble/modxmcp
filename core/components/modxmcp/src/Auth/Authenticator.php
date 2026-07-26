<?php

namespace MODXMCP\Auth;

use MODX\Revolution\modUser;
use MODX\Revolution\modX;
use MODXMCP\Protocol\McpException;

/**
 * Token verification and session-free binding of a MODX user.
 *
 * Two rules here are load-bearing and were established empirically in M0:
 *
 * 1. $_SESSION must already exist as a plain array before modX initializes.
 *    MODX then reports SESSION_STATE_EXTERNAL and never calls session_start(),
 *    so no cookie is issued and nothing is persisted.
 *
 * 2. modUser::addSessionContext() must never be used to grant permissions here.
 *    It is the interactive login path: it regenerates the session id and
 *    increments logincount/lastlogin on the user's profile. Calling it per
 *    request would corrupt the profile and leave a trail of phantom logins.
 *    getAttributes() loads the same access data with none of that.
 */
final class Authenticator
{
    /**
     * Verify a bearer token against a stored hash.
     *
     * Runs before MODX is bootstrapped so that unauthenticated traffic never
     * costs a full CMS init. When tokens move to the modxmcp_token table in M2
     * this ordering flips, and the bootstrap has to happen first.
     *
     * @param array<string,mixed> $config
     * @throws McpException
     */
    public function verifyToken(?string $authorizationHeader, array $config): void
    {
        if (empty($config['enabled'])) {
            throw McpException::unavailable('modxmcp is disabled');
        }

        $header = $authorizationHeader ?? '';
        $token  = (stripos($header, 'Bearer ') === 0) ? substr($header, 7) : '';

        $hash = (string) ($config['token_hash'] ?? '');
        if ($token === '' || $hash === '' || !password_verify($token, $hash)) {
            // password_verify is already constant-time for the comparison; the
            // early returns above only leak whether the server is configured.
            throw McpException::unauthorized();
        }
    }

    /**
     * Bind the MODX user the token acts as, in the mgr context, without
     * creating a session.
     *
     * @throws McpException
     */
    public function bindUser(modX $modx, int $userId): modUser
    {
        /** @var modUser|null $user */
        $user = $modx->getObject(modUser::class, $userId);
        if (!$user || !$user->get('active')) {
            throw McpException::forbidden('Bound MODX user is missing or inactive');
        }

        if ($this->contextKey($modx) === 'mgr') {
            $this->attach($modx, $user);
            return $user;
        }

        $this->enterManagerContext($modx, $user);

        return $user;
    }

    /**
     * Move a web-context request into the mgr context with the user attached.
     *
     * Served as a MODX resource, the request renders in the web context, whose
     * policy does not grant Manager permissions. The ordering below is
     * load-bearing and was measured over HTTP rather than reasoned about:
     *
     *   1. Attach first. modContext::_initContext() only completes if
     *      checkPolicy('load') passes, and for an anonymous user it does not:
     *      switchContext() then returns false and silently reverts to web.
     *   2. Switch. _initContext() runs `$this->user = null; $this->getUser();`
     *      on an already-initialized modX, which discards the user from step 1.
     *   3. Re-attach, to repair that.
     *
     * Step 3 is not defensive tidying. Without it modx->user is left anonymous
     * while hasPermission() still answers true, because the context kept the
     * policy cache computed during step 1. That failure mode looks exactly like
     * success and would silently detach every permission check from the identity
     * it is supposed to be checking.
     *
     * @throws McpException
     */
    private function enterManagerContext(modX $modx, modUser $user): void
    {
        $this->attach($modx, $user);
        $modx->switchContext('mgr');
        $this->attach($modx, $user);

        // Assert the end state rather than trusting the sequence, so a future
        // MODX change surfaces as a clean 403 instead of a privilege confusion.
        if ($this->contextKey($modx) !== 'mgr') {
            throw McpException::forbidden(
                'Could not enter the mgr context. The bound MODX user needs "load" '
                . 'access to the mgr context for modxmcp to act on its behalf.');
        }
        if (!$modx->user || (int) $modx->user->get('id') !== (int) $user->get('id')) {
            throw McpException::internal('User binding was lost during context switch');
        }
    }

    /**
     * Attach the user and load its access attributes.
     *
     * getAttributes() loads the classes named by the principal_targets system
     * setting. Everything downstream, including each processor's own
     * checkPermissions(), reads from what this populates.
     */
    private function attach(modX $modx, modUser $user): void
    {
        $modx->user = $user;
        $modx->user->getAttributes([], 'mgr', true);
    }

    private function contextKey(modX $modx): ?string
    {
        return $modx->context ? (string) $modx->context->get('key') : null;
    }
}
