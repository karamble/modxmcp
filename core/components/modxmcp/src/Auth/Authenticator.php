<?php

namespace MODXMCP\Auth;

use MODX\Revolution\modAccessContext;
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
    private const MANAGER_CONTEXT = 'mgr';

    // Token verification lives in TokenService: it needs the database, so it
    // cannot run before MODX is up the way the M1 file-based config did.

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

        $this->assertNotBlocked($user);

        if ($this->contextKey($modx) === 'mgr') {
            $this->attach($modx, $user);
        } else {
            $this->enterManagerContext($modx, $user);
        }

        $this->assertPolicyIsEnforced($modx, $user);

        return $user;
    }

    /**
     * Honour MODX's block state.
     *
     * Blocking an account is the standard incident response to a compromise,
     * and an administrator who does it reasonably expects the account to stop
     * working everywhere. Checking only `active` would leave every token bound
     * to that user authenticating with full permissions after the human has
     * been locked out, with nothing in the UI hinting that tokens must be
     * revoked separately.
     *
     * Mirrors the logic in MODX's own Security/Login processor: blocked with
     * blockeduntil in the future is a temporary block, blocked with
     * blockeduntil of 0 is an administrator block, and an elapsed temporary
     * block is treated as expired. Unlike the login processor this does not
     * clear the flag: quietly mutating a user profile from an API auth path
     * would be a surprising side effect.
     *
     * @throws McpException
     */
    private function assertNotBlocked(modUser $user): void
    {
        $profile = $user->getOne('Profile');
        if (!$profile) {
            return;
        }

        if ($profile->get('blocked')) {
            $until = (int) $profile->get('blockeduntil');
            if ($until === 0 || $until > time()) {
                throw McpException::forbidden(sprintf(
                    'The MODX user "%s" bound to this token is blocked.',
                    (string) $user->get('username')
                ));
            }
        }

        $after = (int) $profile->get('blockedafter');
        if ($after > 0 && $after < time()) {
            throw McpException::forbidden(sprintf(
                'The MODX user "%s" bound to this token is blocked as of %s.',
                (string) $user->get('username'),
                date('Y-m-d H:i:s', $after)
            ));
        }
    }

    /**
     * Refuse to act as a user whose permissions are not actually enforced.
     *
     * A MODX user belonging to no user group has no access policy on the mgr
     * context, and MODX treats "no policy" as unrestricted rather than as
     * denied. Verified against stock MODX with modxmcp not loaded at all: such a
     * user answers true to save_document AND true to an invented permission
     * name, and successfully creates resources.
     *
     * That inverts the intuition this extra's security story rests on. "The
     * token can never do more than that user can do in the Manager" stays true,
     * but for a groupless user the answer to "what can they do" is "everything".
     * An administrator picking an ordinary-looking account for a least-privilege
     * token would get the opposite of what they intended.
     *
     * The test is structural rather than behavioural. An earlier version probed
     * a random permission name and refused if it came back true, which was
     * wrong: that answer depends on how the context was initialised, so it
     * false-positived on properly grouped users. What actually distinguishes
     * the two cases is the loaded access data itself:
     *
     *   grouped   modAccessContext => ['mgr' => [ ...policy... ], 'web' => [...]]
     *   groupless modAccessContext => []
     *
     * @throws McpException
     */
    private function assertPolicyIsEnforced(modX $modx, modUser $user): void
    {
        // sudo is unrestricted by definition, and openly so.
        if ($user->get('sudo')) {
            return;
        }

        $attributes = $user->getAttributes([], self::MANAGER_CONTEXT);
        $contextAccess = $attributes[modAccessContext::class] ?? [];

        if (!empty($contextAccess[self::MANAGER_CONTEXT])) {
            return;
        }

        throw McpException::forbidden(sprintf(
            'The MODX user "%s" bound to this token belongs to no user group with an access '
            . 'policy on the manager context. MODX treats that as unrestricted rather than as '
            . 'denied, so the user holds every permission and the token would be more privileged '
            . 'than an administrator account, not less. Add the user to a user group with an '
            . 'access policy before using it for a token.',
            (string) $user->get('username')
        ));
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
