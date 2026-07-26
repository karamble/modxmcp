<?php
/**
 * M2 blocker check: can an MCP request served as a MODX resource obtain
 * mgr-context permissions and run mgr processors?
 *
 * A resource renders in the web context, so hasPermission() consults the web
 * policy. modX::switchContext() re-runs _initContext(), which both re-enters the
 * session block and calls getUser($contextKey) - the latter can overwrite the
 * user we bound from the token. This measures the whole chain end to end:
 *
 *   session teardown -> switchContext('mgr') -> bind token user
 *   -> hasPermission -> runProcessor(write) -> SeoSuite fires?
 *   -> session still cookieless afterwards?
 *
 * Also checks the opposite ordering, since if binding before switching is
 * clobbered that is a silent, dangerous failure mode.
 *
 * Creates a snippet + resource, prints URLs. Run with "cleanup" to remove them.
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

const SNIPPET_NAME = 'modxmcpCtxProbe';
const ALIAS        = 'modxmcp-ctx-probe';

if (($argv[1] ?? '') === 'cleanup') {
    foreach ([[modSnippet::class, ['name' => SNIPPET_NAME]], [modResource::class, ['alias' => ALIAS]]] as [$cls, $crit]) {
        if ($obj = $modx->getObject($cls, $crit)) {
            $obj->remove();
            echo "removed {$cls}\n";
        }
    }
    // sweep any test resources the probe failed to clean up itself
    foreach ($modx->getCollection(modResource::class, ['alias:LIKE' => 'modxmcp-ctx-created%']) as $leftover) {
        echo "removed leftover resource " . $leftover->get('id') . "\n";
        $leftover->remove();
    }
    $modx->cacheManager->refresh();
    echo "cache refreshed\n";
    exit;
}

$snippetCode = <<<'PHP'
$out = [];
$prefix = $modx->getOption('table_prefix');

$ssCounts = function () use ($modx, $prefix) {
    $t = [];
    $s = $modx->prepare("SHOW TABLES LIKE '{$prefix}seosuite%'");
    $s->execute();
    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $c = $modx->prepare("SELECT COUNT(*) FROM `{$table}`");
        $c->execute();
        $t[$table] = (int)$c->fetchColumn();
    }
    return $t;
};

// ---- 1. session teardown (proven in the routing probe) ----
$out['session_status_before'] = session_status();
$sid = session_id();
if (session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION = [];
    session_destroy();
    header_remove('Set-Cookie');
}

// ---- 2. baseline: permissions in the web context ----
$out['context_initial'] = $modx->context->get('key');
$out['perm_in_web_context'] = $modx->hasPermission('save_document');

// ---- 3. switch to mgr BEFORE binding the user ----
$out['switch_returned'] = $modx->switchContext('mgr');
$out['context_after_switch'] = $modx->context->get('key');
$out['user_after_switch'] = $modx->user ? $modx->user->get('username') : null;

// ---- 4. bind the token user, session-free ----
$u = $modx->getObject(\MODX\Revolution\modUser::class, ['sudo' => 1]);
$modx->user = $u;
$modx->user->getAttributes([], 'mgr', true);
$out['bound_user'] = $u->get('username');
$out['perm_after_switch_and_bind'] = $modx->hasPermission('save_document');

// ---- 5. did switchContext resurrect the session? ----
$out['session_status_after_switch'] = session_status();
$out['session_id_after_switch'] = session_id();

$rowCheck = 0;
if ($sid !== '') {
    $s = $modx->prepare("SELECT COUNT(*) FROM `{$prefix}session` WHERE id = ?");
    $s->execute([$sid]);
    $rowCheck = (int)$s->fetchColumn();
}
$out['session_row_after_switch'] = $rowCheck;

// ---- 6. the real question: does a WRITE processor work and fire SeoSuite? ----
$before = $ssCounts();
$alias = 'modxmcp-ctx-created-' . substr(md5((string)mt_rand()), 0, 8);
$resp = $modx->runProcessor('Resource/Create', [
    'pagetitle'   => 'modxmcp context switch probe',
    'alias'       => $alias,
    'parent'      => 0,
    'template'    => (int)$modx->getOption('default_template'),
    'published'   => 1,
    'hidemenu'    => 1,
    'context_key' => 'web',
    'content'     => 'created through the resource route',
]);

$r = $resp ? $resp->getResponse() : null;
$out['processor_success'] = is_array($r) ? ($r['success'] ?? null) : null;
$newId = (is_array($r) && !empty($r['success'])) ? (int)($r['object']['id'] ?? 0) : 0;
$out['created_resource_id'] = $newId;
if (is_array($r) && empty($r['success'])) {
    $out['processor_message'] = $r['message'] ?? null;
    $out['processor_errors'] = $r['errors'] ?? null;
}

$after = $ssCounts();
$delta = [];
foreach ($after as $t => $n) {
    if ($n - ($before[$t] ?? 0) !== 0) {
        $delta[$t] = $n - ($before[$t] ?? 0);
    }
}
$out['seosuite_delta'] = $delta ?: 'none';

// ---- 7. clean up immediately: this is a live site ----
if ($newId) {
    if ($obj = $modx->getObject(\MODX\Revolution\modResource::class, $newId)) {
        $obj->remove();
        $out['cleanup_removed'] = $newId;
    }
    $s = $modx->prepare("SHOW TABLES LIKE '{$prefix}seosuite%'");
    $s->execute();
    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $c = $modx->prepare("SHOW COLUMNS FROM `{$table}` LIKE 'resource_id'");
        $c->execute();
        if ($c->fetch()) {
            $d = $modx->prepare("DELETE FROM `{$table}` WHERE resource_id = ?");
            $d->execute([$newId]);
        }
    }
}

header('Content-Type: application/json');
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
exit;
PHP;

$snippet = $modx->getObject(modSnippet::class, ['name' => SNIPPET_NAME]) ?: $modx->newObject(modSnippet::class);
$snippet->set('name', SNIPPET_NAME);
$snippet->set('snippet', $snippetCode);
$snippet->save();

$res = $modx->getObject(modResource::class, ['alias' => ALIAS]) ?: $modx->newObject(modResource::class);
$res->fromArray([
    'pagetitle'    => 'modxmcp context probe',
    'alias'        => ALIAS,
    'parent'       => 0,
    'template'     => 0,
    'published'    => 1,
    'hidemenu'     => 1,
    'searchable'   => 0,
    'cacheable'    => 0,
    'context_key'  => 'web',
    'content_type' => 1,
    'content'      => '[[!' . SNIPPET_NAME . ']]',
]);
$res->save();
$modx->cacheManager->refresh();

echo "snippet id : " . $snippet->get('id') . "\n";
echo "resource id: " . $res->get('id') . "\n";
echo "url        : " . $modx->getOption('site_url') . $res->get('uri') . "\n";
