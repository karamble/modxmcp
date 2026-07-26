<?php
/**
 * modxmcp - MCP endpoint for MODX 3.x
 *
 * Streamable HTTP transport, protocol revision 2026-07-28.
 *
 * This file stays deliberately thin: locate MODX, register the autoloader, wire
 * the server, run. Everything else lives in core/components/modxmcp/src/, which
 * is not web accessible.
 *
 * modxmcp ships no vendor directory and must never gain one. MODX's autoload
 * space is shared by every installed extra, and colliding copies of common
 * packages are a known cause of post-upgrade fatals that cannot be fixed
 * remotely on someone else's site.
 *
 * NOTE for hardened installs: some sites deny PHP execution across
 * assets/components/ via .htaccess. If this endpoint returns 403, that is why.
 * It cannot be fixed from a child .htaccess, because such rules also strip the
 * PHP handler and re-granting access would serve this file as source. Carve out
 * this one directory in the site's hardening instead.
 */

declare(strict_types=1);

// Must precede any MODX code. With $_SESSION present as a plain array, MODX
// reports SESSION_STATE_EXTERNAL and never calls session_start(), so the request
// issues no cookie and persists nothing.
$_SESSION = $_SESSION ?? [];

// --------------------------------------------------------------- locate MODX

// Walked rather than hardcoded: the endpoint may be relocated when the
// conventional location is PHP-denied.
$configCore = null;
for ($dir = __DIR__, $depth = 0; $depth < 6; $depth++) {
    if (is_readable($dir . '/config.core.php')) {
        $configCore = $dir . '/config.core.php';
        break;
    }
    $parent = dirname($dir);
    if ($parent === $dir) {
        break;
    }
    $dir = $parent;
}

if ($configCore === null) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode([
        'jsonrpc' => '2.0',
        'id'      => null,
        'error'   => ['code' => -32603, 'message' => 'Could not locate MODX config.core.php'],
    ]);
    exit;
}

require_once $configCore;

// ------------------------------------------------------------- wire and run

$src = MODX_CORE_PATH . 'components/modxmcp/src/';
require_once $src . 'Autoloader.php';
\MODXMCP\Autoloader::register($src);

// M1: instance config is a deployed file. Superseded by the modxmcp_token table
// in M2, at which point auth moves after the MODX bootstrap.
$configFile = __DIR__ . '/modxmcp.config.php';
$config     = is_readable($configFile) ? (array) require $configFile : [];

$registry = new \MODXMCP\Registry\ToolRegistry();
$registry->register(new \MODXMCP\Tools\SiteInfoTool());

$server = new \MODXMCP\Server(
    new \MODXMCP\Protocol\HttpTransport(),
    new \MODXMCP\Protocol\V20260728(),
    new \MODXMCP\Auth\Authenticator(),
    $registry,
    $config
);

$server->run();
