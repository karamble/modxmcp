<?php
/**
 * modxmcp M0 premise test.
 *
 * Proves the core architectural claim: creating a resource through a MODX
 * processor fires the Manager's full save path (so extras like SeoSuite
 * register the resource), whereas the CLI bridge's raw newObject()+save()
 * does not.
 *
 * A/B: same resource created two ways, SeoSuite tables diffed around each.
 * Both test resources are removed before exit.
 *
 * Run from a MODX web root:  php dev/premise-test.php
 */

error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
define('MODX_API_MODE', true);
require_once 'index.php';

use MODX\Revolution\modX;
use MODX\Revolution\modUser;
use MODX\Revolution\modResource;

$modx = new modX();
$modx->initialize('mgr');
$modx->setLogLevel(modX::LOG_LEVEL_ERROR);

$prefix = $modx->getOption('table_prefix');

/** Snapshot row counts of every seosuite_* table. */
function ssCounts(modX $modx, string $prefix): array
{
    $out = [];
    $stmt = $modx->prepare("SHOW TABLES LIKE '{$prefix}seosuite%'");
    $stmt->execute();
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $c = $modx->prepare("SELECT COUNT(*) FROM `{$table}`");
        $c->execute();
        $out[$table] = (int)$c->fetchColumn();
    }
    return $out;
}

function diffCounts(array $before, array $after): array
{
    $d = [];
    foreach ($after as $t => $n) {
        $delta = $n - ($before[$t] ?? 0);
        if ($delta !== 0) {
            $d[$t] = $delta;
        }
    }
    return $d;
}

function fmtDiff(array $d): string
{
    if (empty($d)) {
        return 'NO SEOSUITE ROWS CREATED';
    }
    $parts = [];
    foreach ($d as $t => $n) {
        $parts[] = "{$t} +{$n}";
    }
    return implode(', ', $parts);
}

// --- session-free auth, exactly as the MCP endpoint will do it ---
$_SESSION = [];                                  // in-memory only; no session_start()
$user = $modx->getObject(modUser::class, ['sudo' => 1]);
if (!$user) {
    exit("FATAL: no sudo user found\n");
}
$modx->user = $user;
$modx->user->getAttributes([], 'mgr', true);     // principal_targets defaults

echo "MODX          : " . ($modx->version['full_version'] ?? '?') . "\n";
echo "user          : " . $user->get('username') . " (id " . $user->get('id') . ")\n";
echo "session state : " . $modx->getSessionState() . "  (2 = EXTERNAL, no cookie)\n";
echo "PHP session id: '" . session_id() . "'  (empty = no real session started)\n";
echo "save_document : " . var_export($modx->hasPermission('save_document'), true) . "\n";
echo "logincount    : " . ($user->getOne('Profile') ? $user->getOne('Profile')->get('logincount') : '?') . " (must not change)\n";
echo str_repeat('=', 72) . "\n";

$parent = 0;
$template = (int)$modx->getOption('default_template');
$created = [];

try {
    // ================= A: processor path (what modxmcp will do) =================
    echo "\n[A] runProcessor('Resource/Create')\n";
    $before = ssCounts($modx, $prefix);

    $resp = $modx->runProcessor('Resource/Create', [
        'pagetitle'    => 'modxmcp premise test A (processor)',
        'alias'        => 'modxmcp-premise-test-a',
        'parent'       => $parent,
        'template'     => $template,
        'published'    => 1,
        'context_key'  => 'web',
        'content'      => 'processor path',
        'hidemenu'     => 1,
    ]);

    $r = $resp ? $resp->getResponse() : null;
    $okA = is_array($r) && !empty($r['success']);
    $idA = $okA ? (int)($r['object']['id'] ?? 0) : 0;
    if ($idA) {
        $created[] = $idA;
    }
    echo "    success: " . var_export($okA, true) . "  id: {$idA}\n";
    if (!$okA) {
        echo "    message: " . (is_array($r) ? json_encode($r['message'] ?? $r) : var_export($r, true)) . "\n";
    }
    $diffA = diffCounts($before, ssCounts($modx, $prefix));
    echo "    seosuite: " . fmtDiff($diffA) . "\n";

    // ============ B: raw xPDO path (what the CLI bridge does today) ============
    echo "\n[B] newObject() + save()  (bridge method)\n";
    $before = ssCounts($modx, $prefix);

    $res = $modx->newObject(modResource::class);
    $res->fromArray([
        'pagetitle'   => 'modxmcp premise test B (raw xpdo)',
        'alias'       => 'modxmcp-premise-test-b',
        'parent'      => $parent,
        'template'    => $template,
        'published'   => 1,
        'context_key' => 'web',
        'content'     => 'raw xpdo path',
        'hidemenu'    => 1,
    ]);
    $okB = $res->save();
    $idB = $okB ? (int)$res->get('id') : 0;
    if ($idB) {
        $created[] = $idB;
    }
    echo "    success: " . var_export((bool)$okB, true) . "  id: {$idB}\n";
    $diffB = diffCounts($before, ssCounts($modx, $prefix));
    echo "    seosuite: " . fmtDiff($diffB) . "\n";

    // ================================ verdict ================================
    echo "\n" . str_repeat('=', 72) . "\n";
    echo "A (processor) seosuite rows: " . (empty($diffA) ? 0 : array_sum($diffA)) . "\n";
    echo "B (raw xpdo)  seosuite rows: " . (empty($diffB) ? 0 : array_sum($diffB)) . "\n";
    if (!empty($diffA) && empty($diffB)) {
        echo "\nVERDICT: PREMISE CONFIRMED. Processor path registers with SeoSuite,\n";
        echo "         raw xPDO path does not. Processor-first is the correct design.\n";
    } elseif (!empty($diffA) && !empty($diffB)) {
        echo "\nVERDICT: BOTH paths register. SeoSuite hooks a model event, not the\n";
        echo "         form event. Processor-first still correct, but this gotcha differs.\n";
    } elseif (empty($diffA) && empty($diffB)) {
        echo "\nVERDICT: NEITHER path registers. SeoSuite may populate lazily on render\n";
        echo "         or via cron. Needs a different probe before trusting the premise.\n";
    } else {
        echo "\nVERDICT: UNEXPECTED (B registered, A did not). Investigate.\n";
    }

    $profile = $user->getOne('Profile');
    echo "\nlogincount after: " . ($profile ? $profile->get('logincount') : '?') . "\n";
    echo "PHP session id  : '" . session_id() . "'\n";

} finally {
    echo "\n" . str_repeat('-', 72) . "\ncleanup:\n";
    foreach ($created as $id) {
        $obj = $modx->getObject(modResource::class, $id);
        if ($obj) {
            $obj->remove();
            echo "  removed resource {$id}\n";
        }
    }
    // drop any seosuite rows pointing at the removed test resources
    if ($created) {
        $in = implode(',', array_map('intval', $created));
        $stmt = $modx->prepare("SHOW TABLES LIKE '{$prefix}seosuite%'");
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $cols = $modx->prepare("SHOW COLUMNS FROM `{$table}` LIKE 'resource_id'");
            $cols->execute();
            if ($cols->fetch()) {
                $del = $modx->prepare("DELETE FROM `{$table}` WHERE resource_id IN ({$in})");
                $del->execute();
                echo "  purged {$table}: " . $del->rowCount() . " row(s)\n";
            }
        }
    }
    $modx->cacheManager->refresh();
    echo "  cache refreshed\n";
}
