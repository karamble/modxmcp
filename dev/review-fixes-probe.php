<?php
/**
 * Verifies the fixes for the five issues raised in the independent review.
 *
 * Run from a MODX web root.
 */

error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_WARNING);
define('MODX_API_MODE', true);
require_once 'index.php';

use MODX\Revolution\modUser;
use MODX\Revolution\modUserProfile;
use MODX\Revolution\modUserGroupMember;
use MODX\Revolution\modSystemSetting;
use MODX\Revolution\modX;

$modx = new modX();
$modx->initialize('mgr');
$modx->setLogLevel(modX::LOG_LEVEL_FATAL);
$_SESSION = [];
$sudo = $modx->getObject(modUser::class, ['sudo' => 1]);
$modx->user = $sudo;
$modx->user->getAttributes([], 'mgr', true);

require_once MODX_CORE_PATH . 'components/modxmcp/src/Runtime.php';
\MODXMCP\Runtime::boot();
\MODXMCP\Package::load($modx);

use MODXMCP\Discovery\ClassGuard;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %-58s %s\n", $ok ? 'PASS' : 'FAIL', $label, $detail);
}

echo "review fixes\n" . str_repeat('=', 84) . "\n";

// ---------------------------------------------- #4 settings + element writes
echo "#4 self-widening and processor-bypass surfaces\n";

// Open both allowlists as wide as they go; the blocks must still hold.
//
// setOption(), not save()+refresh(). Saving the row and refreshing the cache
// does NOT update an already-initialised modX's in-memory config, so the guard
// would still read the old empty value and every "wildcard cannot..." check
// below would pass trivially against a closed allowlist.
foreach (['modxmcp.read_class_allowlist', 'modxmcp.write_class_allowlist'] as $key) {
    $modx->setOption($key, '*');
}

$guard = new ClassGuard($modx);

// Prove the wildcard is actually in force, so the refusals below mean something.
check('wildcard is genuinely active for this instance',
    $guard->canRead('Sterc\\SeoSuite\\Model\\SeoSuiteRedirect'),
    'otherwise every check below is a false pass');
foreach ([
    'MODX\\Revolution\\modSystemSetting',
    'MODX\\Revolution\\modContextSetting',
] as $class) {
    check("wildcard cannot read {$class}", !$guard->canRead($class));
    check("wildcard cannot write {$class}", !$guard->canWrite($class));
}

foreach ([
    'MODX\\Revolution\\modSnippet',
    'MODX\\Revolution\\modPlugin',
    'MODX\\Revolution\\modResource',
    'MODX\\Revolution\\modChunk',
    'MODX\\Revolution\\modTemplate',
    'MODX\\Revolution\\modTemplateVar',
] as $class) {
    check("wildcard cannot generically WRITE {$class}", !$guard->canWrite($class));
}
// Reads of elements stay available; only the write path is closed.
check('elements remain generically readable when allowlisted',
    $guard->canRead('MODX\\Revolution\\modSnippet'));

// restore closed defaults for this instance
foreach (['modxmcp.read_class_allowlist', 'modxmcp.write_class_allowlist'] as $key) {
    $modx->setOption($key, '');
}

// ------------------------------------------------------- #3 blocked users
echo "\n#3 blocked users\n";

$name = 'modxmcp_blockprobe';
if ($old = $modx->getObject(modUser::class, ['username' => $name])) {
    foreach ($modx->getCollection(modUserGroupMember::class, ['member' => $old->get('id')]) as $m) { $m->remove(); }
    if ($p = $old->getOne('Profile')) { $p->remove(); }
    $old->remove();
}
$user = $modx->newObject(modUser::class);
$user->fromArray(['username' => $name, 'active' => 1, 'sudo' => 0]);
$user->set('password', bin2hex(random_bytes(32)));
$user->save();
$profile = $modx->newObject(modUserProfile::class);
$profile->fromArray(['internalKey' => $user->get('id'), 'email' => 'block@example.invalid']);
$profile->save();
$member = $modx->newObject(modUserGroupMember::class);
$member->set('user_group', 1);
$member->set('member', (int) $user->get('id'));
$member->set('role', 2);
$member->save();
$uid = (int) $user->get('id');

$bind = static function (int $id) use ($modx): array {
    $_SESSION = [];
    $x = new modX();
    $x->initialize('web');
    $x->setLogLevel(modX::LOG_LEVEL_FATAL);
    \MODXMCP\Package::load($x);
    try {
        (new \MODXMCP\Auth\Authenticator())->bindUser($x, $id);
        return [true, 'bound'];
    } catch (\Throwable $e) {
        return [false, substr($e->getMessage(), 0, 52)];
    }
};

[$ok, $note] = $bind($uid);
check('unblocked grouped user binds', $ok, $note);

// administrator block: blocked=1, blockeduntil=0
$profile->set('blocked', 1);
$profile->set('blockeduntil', 0);
$profile->save();
[$ok, $note] = $bind($uid);
check('administrator-blocked user is refused', !$ok, $note);

// temporary block still in force
$profile->set('blockeduntil', time() + 3600);
$profile->save();
[$ok, $note] = $bind($uid);
check('temporarily blocked user is refused', !$ok, $note);

// expired temporary block must NOT lock the account out forever
$profile->set('blockeduntil', time() - 3600);
$profile->save();
[$ok, $note] = $bind($uid);
check('expired temporary block binds again', $ok, $note);

// blockedafter in the past
$profile->set('blocked', 0);
$profile->set('blockeduntil', 0);
$profile->set('blockedafter', time() - 60);
$profile->save();
[$ok, $note] = $bind($uid);
check('blockedafter in the past is refused', !$ok, $note);

foreach ($modx->getCollection(modUserGroupMember::class, ['member' => $uid]) as $m) { $m->remove(); }
if ($p = $user->getOne('Profile')) { $p->remove(); }
$user->remove();

// ------------------------------------------------------------- #5 retention
echo "\n#5 audit retention\n";
$logger = new \MODXMCP\Audit\AuditLogger(false);
$removed = $logger->prune($modx, 3650);
check('prune() runs and reports a count', is_int($removed), "removed {$removed} rows older than 10y");

echo str_repeat('=', 84) . "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
