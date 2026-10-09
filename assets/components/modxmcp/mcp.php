<?php
/**
 * modxmcp - standalone MCP endpoint.
 *
 * OPTIONAL. The supported route is the modxmcp snippet in a dedicated resource,
 * which needs no web-accessible PHP and therefore survives installs that deny
 * PHP under assets/. This file exists for sites that would rather have a
 * conventional connector. It starts and discards a session exactly as the
 * snippet route does, because MODX evaluates access policies only inside a
 * session it started itself.
 *
 * Disabled by default. The system setting modxmcp.enable_standalone_endpoint
 * must be Yes, or every request here returns 404; the snippet route is
 * unaffected and always available.
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

// $_SESSION must not be touched before MODX starts: a seeded $_SESSION makes
// MODX report SESSION_STATE_EXTERNAL, and in that state it answers yes to every
// permission check. Bootstrap::modx() lets MODX start a session and then
// discards it (Bootstrap::settleSession()).

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

// Disabled by default: this optional connector is surface a site opts into,
// not something every install should expose. Default-deny -- an absent or
// empty setting keeps it closed. Independent of the global modxmcp.enabled
// kill switch (checked in Server::run for both routes) and of the snippet
// route, which this never gates.
if (!$modx->getOption('modxmcp.enable_standalone_endpoint', null, false)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode([
        'jsonrpc' => '2.0',
        'id'      => null,
        'error'   => [
            'code'    => -32601,
            'message' => 'The modxmcp standalone endpoint is disabled. The supported route is the '
                . 'modxmcp snippet resource. To use this file, set the system setting '
                . 'modxmcp.enable_standalone_endpoint to Yes.',
        ],
    ]);
    exit;
}

\MODXMCP\Runtime::server($modx)->run();
