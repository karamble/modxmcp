<?php
/**
 * Adversarial probes against the paths where caller input reaches xPDO.
 *
 * Three questions reasoning alone cannot settle:
 *
 *  1. buildCriteria() validates the field name before the colon but passes the
 *     operator suffix through. xPDO builds SQL from that key. Is the suffix a
 *     SQL injection vector?
 *  2. PHP class names are case-insensitive. ClassGuard's hard-block patterns
 *     are case-SENSITIVE. Can a differently-cased class name reach a blocked
 *     class?
 *  3. Does a token bound to a low-privilege MODX user actually inherit that
 *     user's limits, or does the mgr context switch hand it more than it should?
 *
 * Run from a MODX web root. Creates and removes a low-privilege test user.
 */

error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
define('MODX_API_MODE', true);
require_once 'index.php';

use MODX\Revolution\modX;
use MODX\Revolution\modUser;
use MODX\Revolution\modUserProfile;
use MODX\Revolution\modResource;

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
use MODXMCP\Discovery\PackageScanner;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %-56s %s\n", $ok ? 'PASS' : 'FAIL', $label, $detail);
}

echo "security probes\n" . str_repeat('=', 84) . "\n";

// =========================================================== 1. criteria keys
echo "1. criteria operator suffix reaching xPDO\n";

$scanner = new PackageScanner($modx);
$classes = $scanner->classes();
$target  = null;
foreach (array_keys($classes) as $c) {
    if (stripos($c, 'SeoSuiteRedirect') !== false) { $target = $c; break; }
}
$target = $target ?: array_key_first($classes);

$probe = new class {
    use \MODXMCP\Tools\ObjectSupport;
    public function build(modX $modx, string $class, array $filters): array
    {
        return $this->buildCriteria($modx, $class, $filters);
    }
};

$injections = [
    'id:) OR 1=1 -- ',
    'id:LIKE) UNION SELECT 1,2,3 -- ',
    'id;DROP TABLE modx_users',
    'id:=1 OR 1',
    "id:LIKE' OR '1'='1",
];

foreach ($injections as $attempt) {
    $rejected = false;
    $note = '';
    try {
        $criteria = $probe->build($modx, $target, [$attempt => 1]);
        // Not rejected at validation. Does xPDO produce dangerous SQL?
        $query = $modx->newQuery($target);
        $query->where($criteria);
        $query->prepare();
        $sql = $query->toSQL();
        // A successful injection would break out of the WHERE clause.
        $dangerous = (bool) preg_match('/\bUNION\b|\bDROP\b|--|\bOR\s+1\s*=\s*1\b/i', $sql);
        $rejected  = !$dangerous;
        $note      = $dangerous ? substr($sql, 0, 90) : 'neutralised in SQL';
    } catch (\Throwable $e) {
        $rejected = true;
        $note     = 'rejected: ' . substr($e->getMessage(), 0, 50);
    }
    check('injection blocked: ' . substr($attempt, 0, 28), $rejected, $note);
}

// ====================================================== 2. case-sensitivity
echo "\n2. ClassGuard case sensitivity\n";

$guard = new ClassGuard($modx);
$variants = [
    'MODX\\Revolution\\modUser',
    'modx\\revolution\\moduser',
    'MODX\\REVOLUTION\\MODUSER',
    'MoDx\\ReVoLuTiOn\\ModUser',
];
foreach ($variants as $variant) {
    check('hard-blocked regardless of case: ' . $variant, $guard->isHardBlocked($variant));
}

// ================================================= 3. least-privilege token
echo "\n3. least-privilege user binding\n";

// A user with NO manager access at all.
if ($old = $modx->getObject(modUser::class, ['username' => 'modxmcp_lowpriv'])) {
    if ($p = $old->getOne('Profile')) { $p->remove(); }
    $old->remove();
}
$low = $modx->newObject(modUser::class);
$low->fromArray(['username' => 'modxmcp_lowpriv', 'active' => 1, 'sudo' => 0]);
$low->set('password', bin2hex(random_bytes(32)));
$low->save();
$profile = $modx->newObject(modUserProfile::class);
$profile->fromArray(['internalKey' => $low->get('id'), 'email' => 'lowpriv@example.invalid']);
$profile->save();

$lowId = (int) $low->get('id');

// Bind exactly as the endpoint would, in a fresh web-context modX.
$_SESSION = [];
$probeModx = new modX();
$probeModx->initialize('web');
$probeModx->setLogLevel(modX::LOG_LEVEL_FATAL);

$auth = new \MODXMCP\Auth\Authenticator();
$denied = false;
$detail = '';
try {
    $auth->bindUser($probeModx, $lowId);
    // If binding succeeded, the user must still hold no manager permissions.
    $detail = 'bound; save_document=' . var_export($probeModx->hasPermission('save_document'), true);
    $denied = !$probeModx->hasPermission('save_document');
} catch (\Throwable $e) {
    $denied = true;
    $detail = 'binding refused: ' . substr($e->getMessage(), 0, 60);
}
check('user without mgr access cannot obtain save_document', $denied, $detail);

// Deliberately NOT asserting that runProcessor refuses here. Native MODX does
// allow that write for a groupless user (verified against stock MODX with no
// modxmcp loaded), which is precisely why binding is refused above: modxmcp
// declines to act as such a user at all rather than inheriting a permissive
// policy it cannot narrow.

// Regression guard: the refusal must not catch legitimate least-privilege use.
// A non-sudo user WITH a group has real policies, so it must still bind.
if ($old = $modx->getObject(modUser::class, ['username' => 'modxmcp_grouped'])) {
    foreach ($modx->getCollection(\MODX\Revolution\modUserGroupMember::class, ['member' => $old->get('id')]) as $mm) {
        $mm->remove();
    }
    if ($p = $old->getOne('Profile')) { $p->remove(); }
    $old->remove();
}
$grouped = $modx->newObject(modUser::class);
$grouped->fromArray(['username' => 'modxmcp_grouped', 'active' => 1, 'sudo' => 0]);
$grouped->set('password', bin2hex(random_bytes(32)));
$grouped->save();
$gProfile = $modx->newObject(modUserProfile::class);
$gProfile->fromArray(['internalKey' => $grouped->get('id'), 'email' => 'grouped@example.invalid']);
$gProfile->save();
$group = $modx->getObject(\MODX\Revolution\modUserGroup::class, ['name' => 'Administrator']);
$member = $modx->newObject(\MODX\Revolution\modUserGroupMember::class);
$member->fromArray(['user_group' => $group->get('id'), 'member' => $grouped->get('id'), 'role' => 2], '', true, true);
$member->save();

$_SESSION = [];
$okModx = new modX();
$okModx->initialize('web');
$okModx->setLogLevel(modX::LOG_LEVEL_FATAL);

$bound = false;
$note  = '';
try {
    (new \MODXMCP\Auth\Authenticator())->bindUser($okModx, (int) $grouped->get('id'));
    $bound = $okModx->user && (int) $okModx->user->get('id') === (int) $grouped->get('id');
    $note  = 'context=' . $okModx->context->get('key')
        . ' save_document=' . var_export($okModx->hasPermission('save_document'), true);
} catch (\Throwable $e) {
    $note = 'WRONGLY REFUSED: ' . substr($e->getMessage(), 0, 60);
}
check('a grouped non-sudo user still binds normally', $bound, $note);

// cleanup
foreach ([$low, $grouped] as $u) {
    foreach ($modx->getCollection(\MODX\Revolution\modUserGroupMember::class, ['member' => $u->get('id')]) as $mm) {
        $mm->remove();
    }
    if ($p = $u->getOne('Profile')) { $p->remove(); }
    $u->remove();
}
foreach ($modx->getCollection(modResource::class, ['alias' => 'modxmcp-lowpriv-probe']) as $stray) {
    $stray->remove();
}

echo str_repeat('=', 84) . "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
