<?php
/**
 * Definitive check for the modxmcp resource route.
 *
 * Confirms ordering C (bind -> switchContext('mgr') -> re-bind) over real HTTP,
 * with a NON-SUDO manager user, in the web context the front controller gives
 * us. This has to be measured rather than reasoned about: ordering B returned
 * true under CLI but false over HTTP, so the two environments demonstrably
 * disagree about context loading.
 *
 * Asserts the end state is correct by construction, not by stale policy cache:
 * context is mgr AND modx->user is our user AND the permission holds AND a
 * write processor fires the extras' Manager-side behaviour.
 *
 * Creates a temp non-sudo user + snippet + resource. Run "cleanup" to remove all.
 */

error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
define('MODX_API_MODE', true);
require_once 'index.php';

use MODX\Revolution\modX;
use MODX\Revolution\modUser;
use MODX\Revolution\modUserProfile;
use MODX\Revolution\modUserGroup;
use MODX\Revolution\modUserGroupMember;
use MODX\Revolution\modSnippet;
use MODX\Revolution\modResource;

const PROBE_USER   = 'modxmcp_probe_tmp';
const SNIPPET_NAME = 'modxmcpCtxProbe';
const ALIAS        = 'modxmcp-ctx-probe';

$modx = new modX();
$modx->initialize('mgr');
$modx->setLogLevel(modX::LOG_LEVEL_ERROR);
$_SESSION = [];
$sudo = $modx->getObject(modUser::class, ['sudo' => 1]);
$modx->user = $sudo;
$modx->user->getAttributes([], 'mgr', true);

function purge(modX $modx): void
{
    if ($u = $modx->getObject(modUser::class, ['username' => PROBE_USER])) {
        foreach ($modx->getCollection(modUserGroupMember::class, ['member' => $u->get('id')]) as $m) {
            $m->remove();
        }
        if ($p = $u->getOne('Profile')) {
            $p->remove();
        }
        $u->remove();
    }
    foreach ([[modSnippet::class, ['name' => SNIPPET_NAME]], [modResource::class, ['alias' => ALIAS]]] as [$cls, $crit]) {
        if ($o = $modx->getObject($cls, $crit)) {
            $o->remove();
        }
    }
    foreach ($modx->getCollection(modResource::class, ['alias:LIKE' => 'modxmcp-ctx-created%']) as $leftover) {
        $leftover->remove();
    }
}

if (($argv[1] ?? '') === 'cleanup') {
    purge($modx);
    $modx->cacheManager->refresh();
    echo "all probe artefacts removed\n";
    exit;
}

purge($modx);

// ------------------------------------------------- temp non-sudo manager user

$group = $modx->getObject(modUserGroup::class, ['name' => 'Administrator']);
$user  = $modx->newObject(modUser::class);
$user->fromArray(['username' => PROBE_USER, 'active' => 1, 'sudo' => 0]);
$user->set('password', bin2hex(random_bytes(32)));
$user->save();

$profile = $modx->newObject(modUserProfile::class);
$profile->fromArray(['internalKey' => $user->get('id'), 'email' => 'probe@example.invalid']);
$profile->save();

$member = $modx->newObject(modUserGroupMember::class);
$member->fromArray(['user_group' => $group->get('id'), 'member' => $user->get('id'), 'role' => 2]);
$member->save();

$probeId = (int) $user->get('id');

$snippetCode = str_replace('__PROBE_ID__', (string) $probeId, <<<'PHP'
$out = [];
$prefix = $modx->getOption('table_prefix');
$probeId = (int)'__PROBE_ID__';

$ssCounts = function () use ($modx, $prefix) {
    $t = [];
    $s = $modx->prepare("SHOW TABLES LIKE '{$prefix}seosuite%'");
    $s->execute();
    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $c = $modx->prepare("SELECT COUNT(*) FROM `{$table}`"); $c->execute();
        $t[$table] = (int)$c->fetchColumn();
    }
    return $t;
};
$bind = function () use ($modx, $probeId) {
    $u = $modx->getObject(\MODX\Revolution\modUser::class, $probeId);
    $modx->user = $u;
    $modx->user->getAttributes([], 'mgr', true);
    return $u;
};

// 1. session teardown
$sid = session_id();
if (session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION = []; session_destroy(); header_remove('Set-Cookie');
}

$out['context_initial'] = $modx->context->get('key');
$out['perm_web_anonymous'] = $modx->hasPermission('save_document');

// 2. ordering C: bind -> switch -> re-bind
$bind();
$out['switch_returned'] = $modx->switchContext('mgr');
$out['user_clobbered_by_switch'] = (!$modx->user || (int)$modx->user->get('id') !== $probeId);
$bind();

// 3. end state must be correct by construction, not by stale policy cache
$out['context_final'] = $modx->context->get('key');
$out['user_final'] = $modx->user ? $modx->user->get('username') : null;
$out['user_final_is_sudo'] = $modx->user ? (int)$modx->user->get('sudo') : null;
$out['perm_save_document'] = $modx->hasPermission('save_document');

// 4. negative control: a permission this user should NOT have implies the
//    check is real rather than blanket-allow
$out['perm_bogus_control'] = $modx->hasPermission('modxmcp_nonexistent_permission');

// 5. session must still be gone after switchContext re-ran _initContext
$out['session_status'] = session_status();
$out['session_id'] = session_id();
$rows = 0;
if ($sid !== '') {
    $s = $modx->prepare("SELECT COUNT(*) FROM `{$prefix}session` WHERE id = ?");
    $s->execute([$sid]); $rows = (int)$s->fetchColumn();
}
$out['session_row'] = $rows;

// 6. the payoff: write processor as a NON-SUDO user, does SeoSuite fire?
$before = $ssCounts();
$resp = $modx->runProcessor('Resource/Create', [
    'pagetitle' => 'modxmcp nonsudo probe',
    'alias' => 'modxmcp-ctx-created-' . substr(md5((string)mt_rand()), 0, 8),
    'parent' => 0, 'template' => (int)$modx->getOption('default_template'),
    'published' => 1, 'hidemenu' => 1, 'context_key' => 'web',
    'content' => 'created by non-sudo user through the resource route',
]);
$r = $resp ? $resp->getResponse() : null;
$out['processor_success'] = is_array($r) ? ($r['success'] ?? null) : null;
$newId = (is_array($r) && !empty($r['success'])) ? (int)($r['object']['id'] ?? 0) : 0;
if (is_array($r) && empty($r['success'])) { $out['processor_message'] = $r['message'] ?? null; }

$after = $ssCounts();
$delta = [];
foreach ($after as $t => $n) { if ($n - ($before[$t] ?? 0) !== 0) { $delta[$t] = $n - ($before[$t] ?? 0); } }
$out['seosuite_delta'] = $delta ?: 'none';

// 7. clean up the created resource immediately
if ($newId) {
    if ($o = $modx->getObject(\MODX\Revolution\modResource::class, $newId)) { $o->remove(); }
    $s = $modx->prepare("SHOW TABLES LIKE '{$prefix}seosuite%'"); $s->execute();
    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $c = $modx->prepare("SHOW COLUMNS FROM `{$table}` LIKE 'resource_id'"); $c->execute();
        if ($c->fetch()) { $d = $modx->prepare("DELETE FROM `{$table}` WHERE resource_id = ?"); $d->execute([$newId]); }
    }
    $out['cleanup_removed'] = $newId;
}

header('Content-Type: application/json');
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
exit;
PHP);

$snippet = $modx->newObject(modSnippet::class);
$snippet->set('name', SNIPPET_NAME);
$snippet->set('snippet', $snippetCode);
$snippet->save();

$res = $modx->newObject(modResource::class);
$res->fromArray([
    'pagetitle' => 'modxmcp ctx probe', 'alias' => ALIAS, 'parent' => 0,
    'template' => 0, 'published' => 1, 'hidemenu' => 1, 'searchable' => 0,
    'cacheable' => 0, 'context_key' => 'web', 'content_type' => 1,
    'content' => '[[!' . SNIPPET_NAME . ']]',
]);
$res->save();
$modx->cacheManager->refresh();

echo "probe user id: {$probeId} (non-sudo)\n";
echo "url          : " . $modx->getOption('site_url') . $res->get('uri') . "\n";
