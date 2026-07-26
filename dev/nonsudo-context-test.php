<?php
/**
 * Decides how modxmcp obtains mgr permissions when the request is served in the
 * web context (which is what the resource route gives us).
 *
 * The earlier probe was a false positive: it bound a sudo user, and sudo
 * bypasses ACL entirely, so it could not distinguish a working context switch
 * from a broken one. This uses a NON-SUDO manager user, which is what a real
 * least-privilege token will bind to.
 *
 * Orderings under test:
 *   A. bind -> switchContext          (switch nulls modx->user, so expect clobber)
 *   B. switch -> bind                 (anonymous cannot load mgr, so expect fail)
 *   C. bind -> switch -> re-bind      (proposed fix)
 *
 * Creates a temporary non-sudo manager user and removes it before exiting.
 */

error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
define('MODX_API_MODE', true);
require_once 'index.php';

use MODX\Revolution\modX;
use MODX\Revolution\modUser;
use MODX\Revolution\modUserProfile;
use MODX\Revolution\modUserGroupMember;
use MODX\Revolution\modUserGroup;

const PROBE_USER = 'modxmcp_probe_tmp';

$boot = new modX();
$boot->initialize('mgr');
$boot->setLogLevel(modX::LOG_LEVEL_ERROR);
$_SESSION = [];
$sudo = $boot->getObject(modUser::class, ['sudo' => 1]);
$boot->user = $sudo;
$boot->user->getAttributes([], 'mgr', true);

// ------------------------------------------------------- temp user lifecycle

function removeProbeUser(modX $modx): void
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
}

if (($argv[1] ?? '') === 'cleanup') {
    removeProbeUser($boot);
    echo "probe user removed\n";
    exit;
}

removeProbeUser($boot);

$group = $boot->getObject(modUserGroup::class, ['name' => 'Administrator']);
if (!$group) {
    exit("FATAL: no Administrator user group\n");
}

$user = $boot->newObject(modUser::class);
$user->fromArray(['username' => PROBE_USER, 'active' => 1, 'sudo' => 0]);
// Long random password: this account must never be loggable-in-to, and it is
// deleted at the end of this script regardless.
$user->set('password', bin2hex(random_bytes(32)));
$user->save();

$profile = $boot->newObject(modUserProfile::class);
$profile->fromArray(['internalKey' => $user->get('id'), 'email' => 'probe@example.invalid', 'fullname' => 'modxmcp probe']);
$profile->save();

$member = $boot->newObject(modUserGroupMember::class);
$member->fromArray(['user_group' => $group->get('id'), 'member' => $user->get('id'), 'role' => 2]);
$member->save();

$probeId = (int) $user->get('id');
echo "probe user id: {$probeId} (non-sudo, Administrator group)\n";
echo str_repeat('=', 70) . "\n";

// --------------------------------------------------- the three orderings

/**
 * Each ordering gets a fresh modX initialized in the WEB context, which is what
 * the resource route produces.
 */
function scenario(string $label, callable $sequence, int $probeId): void
{
    $_SESSION = [];
    $modx = new modX();
    $modx->initialize('web');
    $modx->setLogLevel(modX::LOG_LEVEL_FATAL);

    $result = $sequence($modx, $probeId);

    printf("%s\n", $label);
    printf("  context           : %s\n", $modx->context ? $modx->context->get('key') : 'NULL');
    printf("  modx->user        : %s\n", $modx->user ? $modx->user->get('username') : 'NULL');
    printf("  save_document     : %s\n", var_export($modx->hasPermission('save_document'), true));
    foreach ($result as $k => $v) {
        printf("  %-18s: %s\n", $k, var_export($v, true));
    }
    echo "\n";
}

$bind = static function (modX $modx, int $id): void {
    $u = $modx->getObject(modUser::class, $id);
    $modx->user = $u;
    $modx->user->getAttributes([], 'mgr', true);
};

scenario('A. bind -> switchContext', static function (modX $modx, int $id) use ($bind) {
    $bind($modx, $id);
    $before = $modx->user ? $modx->user->get('username') : null;
    $switched = $modx->switchContext('mgr');
    return ['user_before_switch' => $before, 'switch_returned' => $switched];
}, $probeId);

scenario('B. switchContext -> bind', static function (modX $modx, int $id) use ($bind) {
    $switched = $modx->switchContext('mgr');
    $bind($modx, $id);
    return ['switch_returned' => $switched];
}, $probeId);

scenario('C. bind -> switchContext -> re-bind', static function (modX $modx, int $id) use ($bind) {
    $bind($modx, $id);
    $switched = $modx->switchContext('mgr');
    $clobbered = !$modx->user || $modx->user->get('id') != $id;
    $bind($modx, $id);
    return ['switch_returned' => $switched, 'user_was_clobbered' => $clobbered];
}, $probeId);

// Reference: what the same user gets under a native mgr init.
$_SESSION = [];
$ref = new modX();
$ref->initialize('mgr');
$ref->setLogLevel(modX::LOG_LEVEL_FATAL);
$bind($ref, $probeId);
printf("REFERENCE. native initialize('mgr')\n  context           : %s\n  save_document     : %s\n\n",
    $ref->context->get('key'), var_export($ref->hasPermission('save_document'), true));

removeProbeUser($boot);
echo str_repeat('=', 70) . "\nprobe user removed\n";
