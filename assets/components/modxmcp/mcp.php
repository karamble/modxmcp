<?php
/**
 * modxmcp - standalone MCP endpoint.
 *
 * OPTIONAL. The supported route is the modxmcp snippet in a dedicated resource,
 * which needs no web-accessible PHP and therefore survives installs that deny
 * PHP under assets/. This file exists for sites that would rather have a
 * conventional connector, and it skips the session teardown the snippet route
 * has to perform.
 *
 * If this returns 403, the site denies PHP execution in this directory. That
 * cannot be undone from a child .htaccess, because such rules also strip the
 * PHP handler and re-granting access would serve this file as source. Use the
 * snippet route instead.
 *
 * modxmcp ships no vendor directory and must never gain one: MODX's autoload
 * space is shared by every installed extra, and colliding copies of common
 * packages are a known cause of post-upgrade fatals.
 */

declare(strict_types=1);

// Seeded before any MODX code so MODX reports SESSION_STATE_EXTERNAL and never
// starts a session at all on this route.
$_SESSION = $_SESSION ?? [];

// Walked rather than hardcoded, so the file can be relocated.
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
require_once MODX_CORE_PATH . 'components/modxmcp/src/Runtime.php';

\MODXMCP\Runtime::boot();

$modx = \MODXMCP\Bootstrap::modx();
\MODXMCP\Runtime::server($modx)->run();
