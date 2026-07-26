<?php
/**
 * SB pilot setup.
 *
 * Creates a dedicated MODX user for the modxmcp agent, issues a token with the
 * full scope set, and enables the endpoint.
 *
 * The account is Administrator-group but NOT sudo, deliberately. sudo bypasses
 * MODX's ACL entirely, which would make modxmcp's permission enforcement
 * untestable during the very pilot meant to test it. Administrator + non-sudo
 * gives the full tool scope while the checks still genuinely run.
 *
 * Its password is 64 random hex characters that are never printed or stored
 * anywhere: the account exists to be an API identity, not a login. Revoke the
 * pilot by deactivating the token, or remove the account entirely with
 * `php dev/sb-pilot-setup.php teardown`.
 *
 * Run from the SB web root as the web user:
 *   cd /var/www/singularitybyte.com/web && sudo -u web44 php8.3 /tmp/sb-pilot-setup.php
 */

error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_WARNING);
define('MODX_API_MODE', true);
require_once 'index.php';

use MODX\Revolution\modUser;
use MODX\Revolution\modUserProfile;
use MODX\Revolution\modUserGroupMember;
use MODX\Revolution\modSystemSetting;
use MODX\Revolution\modResource;
use MODX\Revolution\modX;

const AGENT_USER   = 'modxmcp-agent';
const ADMIN_GROUP  = 1;   // Administrator
const SUPERUSER    = 2;   // role: Super User

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

function removeAgent(modX $modx): void
{
    if ($user = $modx->getObject(modUser::class, ['username' => AGENT_USER])) {
        foreach ($modx->getCollection(modUserGroupMember::class, ['member' => $user->get('id')]) as $m) {
            $m->remove();
        }
        if ($profile = $user->getOne('Profile')) {
            $profile->remove();
        }
        $user->remove();
        echo "removed user " . AGENT_USER . "\n";
    }
}

// ------------------------------------------------------------------ teardown

if (($argv[1] ?? '') === 'teardown') {
    $prefix = $modx->getOption('table_prefix');
    $modx->exec("UPDATE {$prefix}modxmcp_token SET active = 0");
    echo "revoked all modxmcp tokens\n";

    $setting = $modx->getObject(modSystemSetting::class, ['key' => 'modxmcp.enabled']);
    $setting->set('value', '0');
    $setting->save();
    echo "modxmcp.enabled = 0\n";

    removeAgent($modx);
    $modx->cacheManager->refresh();
    echo "teardown complete. The package and audit log are left in place.\n";
    exit;
}

// --------------------------------------------------------------------- setup

removeAgent($modx);

$user = $modx->newObject(modUser::class);
$user->fromArray(['username' => AGENT_USER, 'active' => 1, 'sudo' => 0]);
$user->set('password', bin2hex(random_bytes(32)));
if (!$user->save()) {
    exit("FATAL: could not create the agent user\n");
}

$profile = $modx->newObject(modUserProfile::class);
$profile->fromArray([
    'internalKey' => $user->get('id'),
    'email'       => 'modxmcp-agent@singularitybyte.com',
    'fullname'    => 'modxmcp agent (API only, do not log in as this)',
]);
$profile->save();

$member = $modx->newObject(modUserGroupMember::class);
$member->set('user_group', ADMIN_GROUP);
$member->set('member', (int) $user->get('id'));
$member->set('role', SUPERUSER);
$member->save();

$agentId = (int) $user->get('id');
echo "agent user: {$agentId} (" . AGENT_USER . ", Administrator group, sudo=0)\n";

// Confirm the identity behaves as intended before handing it a token.
$_SESSION = [];
$probe = new modX();
$probe->initialize('web');
$probe->setLogLevel(modX::LOG_LEVEL_FATAL);
\MODXMCP\Package::load($probe);
(new \MODXMCP\Auth\Authenticator())->bindUser($probe, $agentId);
echo "bound ok, context = " . $probe->context->get('key') . "\n";
foreach (['save_document', 'delete_document', 'publish_document', 'save_chunk', 'settings', 'empty_cache'] as $perm) {
    printf("  %-18s %s\n", $perm, var_export($probe->hasPermission($perm), true));
}

$issued = (new \MODXMCP\Auth\TokenService())->issue(
    $modx,
    'SB pilot',
    $agentId,
    ['read', 'write:content', 'write:elements', 'write:objects']
);

$enabled = $modx->getObject(modSystemSetting::class, ['key' => 'modxmcp.enabled']);
$enabled->set('value', '1');
$enabled->save();

$modx->cacheManager->refresh();

$endpoint = $modx->getObject(modResource::class, ['content:LIKE' => '%[[!modxmcp%', 'deleted' => 0]);

echo "\n" . str_repeat('=', 66) . "\n";
echo "endpoint : https://singularitybyte.com/" . ($endpoint ? $endpoint->get('uri') : '?') . "\n";
echo "token    : " . $issued['token'] . "\n";
echo str_repeat('=', 66) . "\n";
echo "Generic object access stays OFF (both allowlists empty), so nothing can\n";
echo "read GoodNews subscriber data or OxaPay rows. The curated resource and\n";
echo "element tools do not need it.\n";
