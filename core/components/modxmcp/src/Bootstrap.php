<?php

namespace MODXMCP;

use MODX\Revolution\modX;
use MODXMCP\Protocol\McpException;

/**
 * The two ways an MCP request can reach us.
 *
 * Snippet route (default): the endpoint is a MODX resource, so MODX is already
 * running by the time we get control, and has usually started a session.
 *
 * Standalone route: a PHP entry point that owns its own bootstrap, for installs
 * that prefer a connector file and do not deny PHP under assets/.
 *
 * Both routes end in settleSession(), because MODX evaluates access policies
 * only inside a session it started itself.
 */
final class Bootstrap
{
    /**
     * Undo the session MODX started during initialize().
     *
     * The front controller always starts one when anonymous_sessions is on (the
     * default), well before a snippet can run, so it cannot be prevented, only
     * reversed. Measured behaviour:
     *
     *  - session_destroy() takes the status from active back to none
     *  - header_remove('Set-Cookie') drops the queued cookie, so the response
     *    carries none at all
     *  - the modx_session row is never written, because MODX only persists it on
     *    shutdown and by then the session is gone
     *
     * Token auth is the only identity this endpoint honours, so carrying a
     * session forward would add an ambient credential and nothing else.
     */
    public static function tearDownSession(): void
    {
        // Seeding $_SESSION is safe only after MODX has reached
        // SESSION_STATE_INITIALIZED, which settleSession() sees to: before
        // that, a set $_SESSION latches the state at EXTERNAL, and in that
        // state every permission check answers yes.
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            @session_destroy();
            header_remove('Set-Cookie');
        }
        if (!isset($_SESSION)) {
            $_SESSION = [];
        }
    }

    /**
     * Let MODX start the session it needs to evaluate access policies, then
     * undo it.
     *
     * modAccessibleObject::checkPolicy() consults sudo and the policy only
     * while modX::getSessionState() is SESSION_STATE_INITIALIZED. In every
     * other state it answers true, for every user and every permission,
     * processors included. Only startSession() reaches INITIALIZED, and only
     * from UNINITIALIZED; once reached, the state is never re-evaluated. So
     * starting a session and destroying it at once leaves MODX evaluating
     * policies for the rest of the request, with no cookie sent and no
     * modx_session row written.
     *
     * Usually MODX has already started one during initialize(), and this only
     * tears it down. It has not when anonymous_sessions is off and the request
     * carries no session cookie. _initSession() still registered MODX's own
     * session handler, name and cookie parameters in that case, so the session
     * started here is the one MODX would have started.
     *
     * The standalone route used to seed $_SESSION before initialize() so that
     * no session would start at all. That pinned the state at EXTERNAL, and
     * every permission check on that route, read or write, answered yes.
     */
    public static function settleSession(modX $modx): void
    {
        if ($modx->getSessionState() === modX::SESSION_STATE_UNINITIALIZED) {
            $modx->startSession();
        }
        self::tearDownSession();
    }

    /**
     * Initialize MODX for the standalone route.
     *
     * Nothing touches $_SESSION before initialize(): MODX has to start the
     * session itself for its policies to be evaluated, and settleSession()
     * discards it again before any output.
     *
     * @throws McpException
     */
    public static function modx(): modX
    {
        if (!defined('MODX_API_MODE')) {
            define('MODX_API_MODE', true);
        }
        if (!defined('MODX_CORE_PATH')) {
            throw McpException::unavailable('MODX_CORE_PATH is not defined');
        }

        require_once MODX_CORE_PATH . 'config/' . MODX_CONFIG_KEY . '.inc.php';
        // Core's own autoloader. modxmcp contributes nothing to the shared
        // autoload space and must never start.
        require_once MODX_CORE_PATH . 'vendor/autoload.php';

        $modx = new modX();
        $modx->initialize('mgr');
        $modx->setLogLevel(modX::LOG_LEVEL_ERROR);
        self::settleSession($modx);

        return $modx;
    }
}
