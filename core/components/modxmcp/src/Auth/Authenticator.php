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
     * Bind the MODX user the token acts as, loading its access attributes
     * without creating a session.
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

        $modx->user = $user;

        // Loads the classes named by the principal_targets system setting
        // (modAccessContext, modAccessResourceGroup, modAccessCategory,
        // modAccessMediaSource, modAccessNamespace). Everything downstream,
        // including every processor's own checkPermissions(), reads from this.
        $modx->user->getAttributes([], 'mgr', true);

        return $user;
    }
}
