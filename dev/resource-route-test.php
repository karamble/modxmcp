<?php
/**
 * Tests whether a MODX resource can serve as an MCP endpoint.
 *
 * A resource is rendered by MODX's front controller, which has already started
 * a session and queued a Set-Cookie by the time any snippet runs. This checks
 * whether a snippet can undo that cleanly:
 *
 *   1. is the session actually active when the snippet runs?
 *   2. does session_destroy() remove the modx_session row?
 *   3. does header_remove('Set-Cookie') suppress the cookie?
 *   4. can the snippet emit raw JSON and exit without the render pipeline
 *      mangling it?
 *
 * Creates a snippet + resource, prints the URL, and leaves them for the caller
 * to curl. Run with "cleanup" to remove both.
 */

error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
define('MODX_API_MODE', true);
require_once 'index.php';

use MODX\Revolution\modX;
use MODX\Revolution\modSnippet;
use MODX\Revolution\modResource;
use MODX\Revolution\modUser;

$modx = new modX();
$modx->initialize('mgr');
$modx->setLogLevel(modX::LOG_LEVEL_ERROR);

$_SESSION = [];
$sudo = $modx->getObject(modUser::class, ['sudo' => 1]);
$modx->user = $sudo;
$modx->user->getAttributes([], 'mgr', true);

const SNIPPET_NAME = 'modxmcpRouteProbe';
const ALIAS        = 'modxmcp-route-probe';

// ------------------------------------------------------------------ cleanup

if (($argv[1] ?? '') === 'cleanup') {
    foreach ([[modSnippet::class, ['name' => SNIPPET_NAME]], [modResource::class, ['alias' => ALIAS]]] as [$cls, $crit]) {
        $obj = $modx->getObject($cls, $crit);
        if ($obj) {
            $obj->remove();
            echo "removed " . $cls . "\n";
        }
    }
    $modx->cacheManager->refresh();
    echo "cache refreshed\n";
    exit;
}

// ------------------------------------------------------------------- create

$snippetCode = <<<'PHP'
$prefix = $modx->getOption('table_prefix');

$sidBefore   = session_id();
$statusBefore = session_status();   // 2 = PHP_SESSION_ACTIVE

// Did MODX persist a session row for this request?
$rowBefore = 0;
if ($sidBefore !== '') {
    $s = $modx->prepare("SELECT COUNT(*) FROM `{$prefix}session` WHERE id = ?");
    $s->execute([$sidBefore]);
    $rowBefore = (int)$s->fetchColumn();
}

// The teardown under test.
$destroyed = false;
if (session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION = [];
    $destroyed = session_destroy();
    header_remove('Set-Cookie');
}

$rowAfter = 0;
if ($sidBefore !== '') {
    $s = $modx->prepare("SELECT COUNT(*) FROM `{$prefix}session` WHERE id = ?");
    $s->execute([$sidBefore]);
    $rowAfter = (int)$s->fetchColumn();
}

header('Content-Type: application/json');
header('X-Probe: reached-snippet');
echo json_encode([
    'reached_snippet'  => true,
    'session_id'       => $sidBefore,
    'status_before'    => $statusBefore,
    'status_after'     => session_status(),
    'session_row_before' => $rowBefore,
    'session_row_after'  => $rowAfter,
    'destroy_returned' => $destroyed,
    'headers_sent'     => headers_sent(),
], JSON_PRETTY_PRINT);
exit;
PHP;

$snippet = $modx->getObject(modSnippet::class, ['name' => SNIPPET_NAME]);
if (!$snippet) {
    $snippet = $modx->newObject(modSnippet::class);
    $snippet->set('name', SNIPPET_NAME);
}
$snippet->set('snippet', $snippetCode);
$snippet->save();
echo "snippet id: " . $snippet->get('id') . "\n";

$res = $modx->getObject(modResource::class, ['alias' => ALIAS]);
if (!$res) {
    $res = $modx->newObject(modResource::class);
}
$res->fromArray([
    'pagetitle'    => 'modxmcp route probe',
    'alias'        => ALIAS,
    'parent'       => 0,
    'template'     => 0,          // blank: nothing wraps our output
    'published'    => 1,
    'hidemenu'     => 1,
    'searchable'   => 0,
    'cacheable'    => 0,          // must not be served from cache
    'context_key'  => 'web',
    'content_type' => 1,
    'content'      => '[[!' . SNIPPET_NAME . ']]',
]);
$res->save();

$modx->cacheManager->refresh();

$id = (int)$res->get('id');
echo "resource id: {$id}\n";
echo "url (alias): " . $modx->getOption('site_url') . ALIAS . "\n";
echo "url (id)   : " . $modx->getOption('site_url') . "index.php?id={$id}\n";
