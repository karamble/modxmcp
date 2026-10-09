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
 * 1. MODX must have started a session itself before any permission is
 *    checked. modAccessibleObject::checkPolicy() evaluates a policy only in
 *    SESSION_STATE_INITIALIZED and answers yes in every other state. This rule
 *    used to read the other way round: seed $_SESSION before modX initializes,
 *    so MODX would report SESSION_STATE_EXTERNAL and issue no cookie. That
 *    did avoid the cookie, and it also made every permission check on the
 *    standalone route a yes. Bootstrap::settleSession() now lets MODX start
 *    the session and destroys it before any output, so no cookie is issued and
 *    nothing is persisted, and assertPermissionsEvaluable() refuses a request
 *    where that did not happen.
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
        $this->reloadForeignContexts($modx);

        return $user;
    }

    /**
     * Drop every cached context but the current one, so a tool that loads
     * another gets it evaluated under the bound user.
     *
     * On the snippet route MODX's front controller has already loaded and
     * cached the web context, while the request was still anonymous. modX::
     * getContext() hands back a cached context without re-checking, and
     * loading one in the first place is a checkPolicy('load') -- modContext is
     * a modAccessibleObject, so getObject() returns null when the user may not
     * load it. Resource/Create reads that as access_denied (Create.php:168).
     * Left cached, the anonymous web context lets a bound user who may not load
     * web create resources in it, which the Manager and the standalone route
     * both refuse: on mcp.php nothing pre-loaded web, so the load check runs
     * under the user and the create is refused. Clearing the cache here makes
     * the snippet route do the same. The current context (mgr, just entered)
     * is kept, since $modx->context points at it and the request runs in it.
     *
     * Reads are unaffected: they query by context_key and never load the
     * context object for a policy.
     */
    private function reloadForeignContexts(modX $modx): void
    {
        $current = $this->contextKey($modx);
        foreach (array_keys($modx->contexts) as $key) {
            if ((string) $key !== (string) $current) {
                unset($modx->contexts[$key]);
            }
        }
    }

    /**
     * Refuse to act as a non-sudo user on a request where MODX would not
     * evaluate its access policies.
     *
     * In any session state but SESSION_STATE_INITIALIZED,
     * modAccessibleObject::checkPolicy() answers true without looking, so
     * every hasPermission() a tool makes and every processor's
     * checkPermissions() would be a yes. Bootstrap::settleSession() puts both
     * routes in the right state. This is what makes a request that still is
     * not fail closed rather than open.
     *
     * Sudo is exempt because its answer does not depend on the state:
     * checkPolicy() returns true for sudo in every state.
     *
     * Deliberately not part of bindUser(). The in-process dev probes bind
     * users under CLI, where MODX reports SESSION_STATE_UNAVAILABLE, and they
     * must keep binding.
     *
     * @throws McpException
     */
    public function assertPermissionsEvaluable(modX $modx, modUser $user): void
    {
        if ($user->get('sudo')) {
            return;
        }

        $state = $modx->getSessionState();
        if ($state === modX::SESSION_STATE_INITIALIZED) {
            return;
        }

        throw McpException::unavailable(sprintf(
            'modxmcp will not act as the MODX user "%s" on this request: MODX reports session '
            . 'state %d, and it evaluates access policies only in state %d (a session it started '
            . 'itself). In any other state every permission check answers yes, so the request is '
            . 'refused rather than served without them. The usual cause is the system setting '
            . 'anonymous_sessions turned off while this endpoint is served through the snippet '
            . 'resource: the front controller then starts no session and has already fixed the '
            . 'state by the time modxmcp runs. Turn anonymous_sessions on (its default), or use '
            . 'the standalone endpoint (assets/components/modxmcp/mcp.php), which starts its own '
            . 'session. Nothing was read or written.',
            (string) $user->get('username'),
            $state,
            modX::SESSION_STATE_INITIALIZED
        ));
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
     * context, and an account that is intentionally restricted is
     * indistinguishable here from one that is simply misconfigured. modxmcp
     * refuses both, because it cannot tell which was meant.
     *
     * An older comment here claimed such a user holds every permission. That
     * was read off the bypass state this extra used to run in, where no policy
     * was evaluated for anyone (see rule 1 in the class docblock). With
     * policies evaluated, the opposite is true: the mgr context carries ACLs,
     * the groupless user matches no principal in them, and
     * modAccessibleObject::checkPolicy() falls through to deny. So the bind is
     * refused here, and MODX would deny the user anyway; an administrator
     * picking an ordinary-looking account for a least-privilege token gets a
     * clear refusal at the first request rather than a surprise either way.
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
            . 'policy on the manager context, so modxmcp cannot act as it: an account with no '
            . 'policy cannot be told apart from a misconfigured one, and MODX denies it in any '
            . 'case once policies are evaluated. Add the user to a user group with an access '
            . 'policy before using it for a token.',
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
