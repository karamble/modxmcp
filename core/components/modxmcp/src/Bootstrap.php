<?php

namespace MODXMCP;

use MODX\Revolution\modX;
use MODXMCP\Protocol\McpException;

/**
 * The two ways an MCP request can reach us.
 *
 * Snippet route (default): the endpoint is a MODX resource, so MODX is already
 * running by the time we get control, and it has already started a session.
 *
 * Standalone route: a PHP entry point that owns its own bootstrap, for installs
 * that prefer a connector file and do not deny PHP under assets/.
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
     * Initialize MODX for the standalone route.
     *
     * $_SESSION is seeded first so MODX reports SESSION_STATE_EXTERNAL and never
     * calls session_start() at all: on this route there is nothing to tear down.
     *
     * @throws McpException
     */
    public static function modx(): modX
    {
        if (!isset($_SESSION)) {
            $_SESSION = [];
        }
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

        return $modx;
    }
}
