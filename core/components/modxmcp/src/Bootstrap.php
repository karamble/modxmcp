<?php

namespace MODXMCP;

use MODX\Revolution\modX;
use MODXMCP\Protocol\McpException;

/**
 * Brings MODX up for an MCP request.
 *
 * modxmcp owns its own bootstrap rather than riding MODX's front controller.
 * That is a deliberate choice: MODX starts a PHP session during initialize()
 * whenever anonymous_sessions is on (the default), which happens before any
 * plugin event can fire. Owning the bootstrap is the only way to guarantee the
 * session-free request the token auth model depends on.
 */
final class Bootstrap
{
    // Locating config.core.php necessarily happens in the entry point, before
    // this class can be autoloaded, so that walk lives in mcp.php.

    /**
     * Initialize MODX in the mgr context with no session.
     *
     * @throws McpException
     */
    public static function modx(): modX
    {
        // Ordering is load-bearing, so it is enforced here rather than trusted to
        // the caller: this must happen before modX is constructed.
        self::suppressSession();

        if (!defined('MODX_CORE_PATH')) {
            throw McpException::unavailable('MODX_CORE_PATH is not defined');
        }

        require_once MODX_CORE_PATH . 'config/' . MODX_CONFIG_KEY . '.inc.php';
        // MODX 3 is PSR-4 autoloaded. This is core's own autoloader; modxmcp
        // contributes nothing to the shared autoload space.
        require_once MODX_CORE_PATH . 'vendor/autoload.php';

        $modx = new modX();
        $modx->initialize('mgr');
        $modx->setLogLevel(modX::LOG_LEVEL_ERROR);

        return $modx;
    }

    /**
     * Must run before modX is constructed.
     *
     * With $_SESSION present as a plain array, MODX reports
     * SESSION_STATE_EXTERNAL and never calls session_start(), so the request
     * issues no cookie and persists nothing. Verified in M0: session_state 2,
     * empty session_id, unchanged logincount.
     */
    public static function suppressSession(): void
    {
        if (!isset($_SESSION)) {
            $_SESSION = [];
        }
        if (!defined('MODX_API_MODE')) {
            define('MODX_API_MODE', true);
        }
    }
}
