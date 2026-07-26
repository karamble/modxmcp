<?php
/**
 * Exercises the CMP's processors directly.
 *
 * The ExtJS page itself cannot be driven from here, but the processors behind
 * it are ordinary MODX processors and carry all the behaviour that matters:
 * that a hash is never returned to the UI, that the plaintext is returned
 * exactly once on create, and that revoke deactivates rather than deletes so
 * audit history keeps its referent.
 */

error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
define('MODX_API_MODE', true);
require_once 'index.php';

use MODX\Revolution\modX;
use MODX\Revolution\modUser;

$modx = new modX();
$modx->initialize('mgr');
$modx->setLogLevel(modX::LOG_LEVEL_ERROR);
$_SESSION = [];
$sudo = $modx->getObject(modUser::class, ['sudo' => 1]);
$modx->user = $sudo;
$modx->user->getAttributes([], 'mgr', true);

require_once MODX_CORE_PATH . 'components/modxmcp/src/Runtime.php';
\MODXMCP\Runtime::boot();
\MODXMCP\Package::load($modx);
$modx->lexicon->load('modxmcp:default');

$procPath = MODX_CORE_PATH . 'components/modxmcp/processors/';
$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %-52s %s\n", $ok ? 'PASS' : 'FAIL', $label, $detail);
}

function run(modX $modx, string $procPath, string $action, array $props = []): array
{
    $resp = $modx->runProcessor($action, $props, ['processors_path' => $procPath]);
    if (!$resp) {
        return ['success' => false, 'message' => 'processor did not run'];
    }
    $out = $resp->getResponse();
    return is_array($out) ? $out : (array) json_decode((string) $out, true);
}

echo "CMP processors\n" . str_repeat('=', 78) . "\n";

// --- create -----------------------------------------------------------------
$created = run($modx, $procPath, 'mgr/token/create', [
    'name'    => 'cmp processor test',
    'user_id' => (int) $sudo->get('id'),
    'scopes'  => 'read, write:content',
]);
check('create succeeds', !empty($created['success']));
$plaintext = $created['object']['token'] ?? '';
$tokenId   = (int) ($created['object']['id'] ?? 0);
check('create returns plaintext once', strpos($plaintext, 'mcp_') === 0,
    $plaintext !== '' ? substr($plaintext, 0, 13) . '...' : '(none)');

// --- validation -------------------------------------------------------------
$bad = run($modx, $procPath, 'mgr/token/create', ['name' => '', 'user_id' => 0]);
check('create rejects empty name and user', empty($bad['success']));

$badUser = run($modx, $procPath, 'mgr/token/create', ['name' => 'x', 'user_id' => 999999]);
check('create rejects unknown user', empty($badUser['success']));

// --- getlist ----------------------------------------------------------------
$list = run($modx, $procPath, 'mgr/token/getlist', ['limit' => 50]);
check('getlist succeeds', isset($list['results']) && is_array($list['results']));

$rows       = $list['results'] ?? [];
$leakedHash = false;
$leakedPlain = false;
foreach ($rows as $row) {
    if (array_key_exists('token_hash', $row)) {
        $leakedHash = true;
    }
    foreach ($row as $v) {
        if (is_string($v) && $plaintext !== '' && $v === $plaintext) {
            $leakedPlain = true;
        }
    }
}
check('getlist never exposes token_hash', !$leakedHash);
check('getlist never exposes plaintext', !$leakedPlain);
check('getlist resolves username', !empty($rows[0]['username'] ?? ''));

// --- the issued token actually authenticates --------------------------------
$svc = new \MODXMCP\Auth\TokenService();
try {
    $verified = $svc->verify($modx, 'Bearer ' . $plaintext, '127.0.0.1');
    check('issued token verifies', (int) $verified->get('id') === $tokenId);
} catch (\Throwable $e) {
    check('issued token verifies', false, $e->getMessage());
}

// --- revoke -----------------------------------------------------------------
$revoked = run($modx, $procPath, 'mgr/token/revoke', ['id' => $tokenId]);
check('revoke succeeds', !empty($revoked['success']));

$still = $modx->getObject(\MODXMCP\Model\ModxmcpToken::class, $tokenId);
check('revoke deactivates rather than deletes', $still !== null && (int) $still->get('active') === 0);

try {
    $svc->verify($modx, 'Bearer ' . $plaintext, '127.0.0.1');
    check('revoked token no longer authenticates', false, 'it still verified');
} catch (\Throwable $e) {
    check('revoked token no longer authenticates', true);
}

// --- audit ------------------------------------------------------------------
$audit = run($modx, $procPath, 'mgr/audit/getlist', ['limit' => 10]);
check('audit getlist succeeds', isset($audit['results']) && is_array($audit['results']));
$auditLeak = false;
foreach ($audit['results'] ?? [] as $row) {
    if (array_key_exists('arguments', $row)) {
        $auditLeak = true;
    }
}
check('audit grid omits raw arguments', !$auditLeak);

$failuresOnly = run($modx, $procPath, 'mgr/audit/getlist', ['limit' => 50, 'failures_only' => 1]);
$allFailed = true;
foreach ($failuresOnly['results'] ?? [] as $row) {
    if ((int) ($row['success'] ?? 1) !== 0) {
        $allFailed = false;
    }
}
check('audit failures_only filters correctly', $allFailed);

// --- cleanup ----------------------------------------------------------------
if ($still) {
    $still->remove();
}

echo str_repeat('=', 78) . "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
