<?php
/**
 * Fixture for dev/permission-test.php: restricted MODX users, the access rules
 * that restrict them, and tokens bound to them.
 *
 *   php dev/permission-fixture.php create  <modx-base-path> <out.json>
 *   php dev/permission-fixture.php audit   <modx-base-path> <out.json>
 *   php dev/permission-fixture.php scrub   <modx-base-path>
 *   php dev/permission-fixture.php cleanup <modx-base-path>
 *
 * scrub removes only what a test run wrote -- resources it created, chunks it
 * saved -- and keeps the users, rules and tokens, so a second run after a code
 * change sees the same site the first one did.
 *
 * Run as the site's PHP user, never as root: the cache refresh at the end of
 * create and cleanup would otherwise leave root-owned files under core/cache.
 *
 * In-process, and that is fine for building the fixture but not for testing
 * it. Under CLI MODX reports SESSION_STATE_UNAVAILABLE, in which every
 * permission check answers yes, so nothing here can observe a refusal. That is
 * what dev/permission-test.php is for: it calls the endpoint over HTTP.
 *
 * What create builds, all named modxmcp_perm_*:
 *
 *   loadonly  a user in its own group with the stock Load Only policy on mgr,
 *             the issue's reproduction: may enter the Manager, may see nothing
 *   editor    a user in its own group with the stock Content Editor policy on
 *             mgr, and Load, List and View on web -- what a real editor has.
 *             Without web, Resource/Create loads its working context through
 *             getContext(), which applies the bound user's load policy, and
 *             refuses with access_denied.
 *   admin     a non-sudo user in the Administrator group, the baseline whose
 *             output must not change
 *   editnoweb a user with Content Editor on mgr but NO web-context ACL. It
 *             holds new_document, so it clears the create processor's own
 *             check, and is then refused because it may not load the web
 *             context -- the snippet-route gap, which must refuse on both
 *             routes.
 *
 *   a parent resource no access rule touches, for the test's writes: a create
 *   under a parent flips that parent's isfolder and recomputes its URI, so a
 *   test must never create under a resource it does not own. (One run used
 *   the home page and turned /index.html into a 404 until it was reverted.)
 *
 *   a probe resource in its own resource group, and a probe template and
 *   chunk in their own category. Each carries two ACLs: the editor group's,
 *   with the Load Only policy, and the Administrator group's, with the stock
 *   Resource or Element policy. The editor's own ACL is the point. An object
 *   in a group with ACLs is checked against them, and a user matching none
 *   fails 'load' -- xPDO then drops the row before any tool sees it, and the
 *   per-row view/list checks under test would never run. With its own Load
 *   Only ACL the editor loads the row and fails 'view' and 'list', which is
 *   exactly the case the tools must refuse or hide.
 *   (modAccessibleObject::_loadInstance and checkPolicy, MODX 3.2.3)
 *
 * Tokens are written to <out.json>, mode 0600. cleanup removes everything
 * create made, the tokens and their audit rows included, and purges any
 * resource the test created under the modxmcp-perm- alias prefix.
 */

use MODX\Revolution\modAccessCategory;
use MODX\Revolution\modAccessContext;
use MODX\Revolution\modAccessPolicy;
use MODX\Revolution\modAccessResourceGroup;
use MODX\Revolution\modCategory;
use MODX\Revolution\modChunk;
use MODX\Revolution\modDocument;
use MODX\Revolution\modResource;
use MODX\Revolution\modResourceGroup;
use MODX\Revolution\modResourceGroupResource;
use MODX\Revolution\modTemplate;
use MODX\Revolution\modUser;
use MODX\Revolution\modUserGroup;
use MODX\Revolution\modUserGroupMember;
use MODX\Revolution\modUserGroupRole;
use MODX\Revolution\modUserProfile;
use MODX\Revolution\modX;

$mode = $argv[1] ?? '';
$base = rtrim($argv[2] ?? '', '/') . '/';
$out  = $argv[3] ?? '';

if (!in_array($mode, ['create', 'audit', 'scrub', 'cleanup'], true) || !is_readable($base . 'config.core.php')
    || (in_array($mode, ['create', 'audit'], true) && $out === '')) {
    exit("usage: php dev/permission-fixture.php create|audit <modx-base-path> <out.json>\n"
        . "       php dev/permission-fixture.php cleanup <modx-base-path>\n");
}

require $base . 'config.core.php';
require MODX_CORE_PATH . 'vendor/autoload.php';

$modx = new modX();
$modx->initialize('mgr');
$modx->setLogLevel(modX::LOG_LEVEL_ERROR);

require_once MODX_CORE_PATH . 'components/modxmcp/src/Runtime.php';
\MODXMCP\Runtime::boot();
\MODXMCP\Package::load($modx);

const PREFIX = 'modxmcp_perm_';
const ALIAS  = 'modxmcp-perm-';

function fail(string $why): void
{
    fwrite(STDERR, "FAILED: {$why}\n");
    exit(1);
}

function saved($object, string $what)
{
    if (!$object->save()) {
        fail("could not save {$what}");
    }
    return $object;
}

/** A policy by name, or fail: the fixture is meaningless without the stock ones. */
function policyId(modX $modx, string $name): int
{
    $policy = $modx->getObject(modAccessPolicy::class, ['name' => $name]);
    if (!$policy) {
        fail("this site has no '{$name}' access policy");
    }
    return (int) $policy->get('id');
}

/** A role by name, falling back to the given id. */
function roleId(modX $modx, string $name, int $fallback): int
{
    $role = $modx->getObject(modUserGroupRole::class, ['name' => $name]);
    return $role ? (int) $role->get('id') : $fallback;
}

function makeUser(modX $modx, string $username, int $groupId, int $roleId): modUser
{
    $user = $modx->newObject(modUser::class);
    $user->fromArray(['username' => $username, 'active' => 1, 'sudo' => 0]);
    $user->set('password', bin2hex(random_bytes(24)));
    saved($user, "user {$username}");

    $profile = $modx->newObject(modUserProfile::class);
    $profile->fromArray(['internalKey' => $user->get('id'), 'email' => $username . '@example.invalid']);
    saved($profile, "profile {$username}");

    $member = $modx->newObject(modUserGroupMember::class);
    $member->fromArray(['user_group' => $groupId, 'member' => $user->get('id'), 'role' => $roleId, 'rank' => 0], '', true, true);
    saved($member, "membership {$username}");

    return $user;
}

/** @param class-string $aclClass */
function grant(modX $modx, string $aclClass, $target, int $groupId, int $policyId, ?string $context = 'mgr'): void
{
    $acl = $modx->newObject($aclClass);
    $fields = [
        'target'          => $target,
        'principal_class' => modUserGroup::class,
        'principal'       => $groupId,
        'authority'       => 9999,
        'policy'          => $policyId,
    ];
    if ($context !== null) {
        $fields['context_key'] = $context;
    }
    $acl->fromArray($fields, '', true, true);
    saved($acl, "{$aclClass} on {$target} for group {$groupId}");
}

function cleanup(modX $modx): void
{
    $removed = [];

    // Tokens and their audit rows first, while the users still exist to find them by.
    $tokenIds = [];
    foreach ($modx->getCollection(\MODXMCP\Model\ModxmcpToken::class, ['name:LIKE' => PREFIX . '%']) as $token) {
        $tokenIds[] = (int) $token->get('id');
        $token->remove();
    }
    if ($tokenIds !== []) {
        $modx->removeCollection(\MODXMCP\Model\ModxmcpAudit::class, ['token_id:IN' => $tokenIds]);
        $removed[] = count($tokenIds) . ' token(s) and their audit rows';
    }

    foreach ($modx->getCollection(modUser::class, ['username:LIKE' => PREFIX . '%']) as $user) {
        $modx->removeCollection(modUserGroupMember::class, ['member' => $user->get('id')]);
        if ($p = $user->getOne('Profile')) {
            $p->remove();
        }
        $removed[] = 'user ' . $user->get('username');
        $user->remove();
    }

    foreach ($modx->getCollection(modResourceGroup::class, ['name:LIKE' => PREFIX . '%']) as $rg) {
        $modx->removeCollection(modAccessResourceGroup::class, ['target' => (string) $rg->get('id')]);
        $modx->removeCollection(modResourceGroupResource::class, ['document_group' => $rg->get('id')]);
        $removed[] = 'resource group ' . $rg->get('name');
        $rg->remove();
    }

    foreach ($modx->getCollection(modCategory::class, ['category:LIKE' => PREFIX . '%']) as $cat) {
        $modx->removeCollection(modAccessCategory::class, ['target' => (string) $cat->get('id')]);
        foreach ([modTemplate::class, modChunk::class] as $class) {
            foreach ($modx->getCollection($class, ['category' => $cat->get('id')]) as $element) {
                $element->remove();
            }
        }
        $removed[] = 'category ' . $cat->get('category');
        $cat->remove();
    }
    foreach ($modx->getCollection(modChunk::class, ['name:LIKE' => PREFIX . '%']) as $chunk) {
        $removed[] = 'chunk ' . $chunk->get('name');
        $chunk->remove();
    }

    // Purged, not soft-deleted: a deleted resource still sits in the trash.
    foreach ($modx->getCollection(modResource::class, ['alias:LIKE' => ALIAS . '%']) as $resource) {
        $removed[] = 'resource ' . $resource->get('id') . ' (' . $resource->get('alias') . ')';
        $resource->remove();
    }

    foreach ($modx->getCollection(modUserGroup::class, ['name:LIKE' => PREFIX . '%']) as $group) {
        $modx->removeCollection(modAccessContext::class, ['principal' => $group->get('id'), 'principal_class' => modUserGroup::class]);
        $removed[] = 'group ' . $group->get('name');
        $group->remove();
    }

    $modx->cacheManager->refresh();

    echo $removed === [] ? "nothing to remove\n" : implode("\n", array_map(fn($r) => "  removed {$r}", $removed)) . "\n";
}

if ($mode === 'cleanup') {
    cleanup($modx);
    exit(0);
}

if ($mode === 'scrub') {
    $n = 0;
    foreach ($modx->getCollection(modResource::class, [
        'alias:LIKE' => ALIAS . '%',
        'alias:NOT IN' => [ALIAS . 'probe', ALIAS . 'parent'],
    ]) as $resource) {
        echo '  removed resource ' . $resource->get('id') . ' (' . $resource->get('alias') . ")\n";
        $resource->remove();
        $n++;
    }
    foreach ($modx->getCollection(modChunk::class, ['name:LIKE' => PREFIX . 'scratch%']) as $chunk) {
        echo '  removed chunk ' . $chunk->get('name') . "\n";
        $chunk->remove();
        $n++;
    }
    $modx->cacheManager->refresh();
    echo $n === 0 ? "  nothing to scrub\n" : '';
    exit(0);
}

if ($mode === 'audit') {
    $fixture = json_decode((string) @file_get_contents($out), true);
    $ids     = array_column($fixture['tokens'] ?? [], 'id');
    if ($ids === []) {
        fail("no token ids in {$out}");
    }
    $query = $modx->newQuery(\MODXMCP\Model\ModxmcpAudit::class, ['token_id:IN' => $ids]);
    $query->sortby('id', 'DESC');
    $query->limit(60);
    foreach ($modx->getCollection(\MODXMCP\Model\ModxmcpAudit::class, $query) as $row) {
        printf("  token=%-3d %-30s success=%d code=%-7s %s\n",
            $row->get('token_id'), $row->get('tool') ?: $row->get('rpc_method'), $row->get('success'),
            (string) $row->get('error_code'), substr((string) $row->get('error_message'), 0, 70));
    }
    exit(0);
}

// ------------------------------------------------------------------ create
cleanup($modx);
echo "creating\n";

$loadOnly = policyId($modx, modAccessPolicy::POLICY_LOAD_ONLY);
$loadView = policyId($modx, modAccessPolicy::POLICY_LOAD_LIST_VIEW);
$editor   = policyId($modx, modAccessPolicy::POLICY_CONTENT_EDITOR);
$resource = policyId($modx, modAccessPolicy::POLICY_RESOURCE);
$element  = policyId($modx, modAccessPolicy::POLICY_ELEMENT);

$admins = $modx->getObject(modUserGroup::class, ['name' => 'Administrator']);
if (!$admins) {
    fail('this site has no Administrator user group');
}
$adminGroupId = (int) $admins->get('id');

// Member (authority 9999) satisfies the 9999 ACLs below. The Administrator
// group's own mgr ACL is usually authority 0, which only Super User (0) meets.
$member    = roleId($modx, 'Member', 1);
$superUser = roleId($modx, 'Super User', 2);

$groups = [];
foreach (['loadonly' => $loadOnly, 'editor' => $editor, 'editnoweb' => $editor] as $key => $policy) {
    $group = saved($modx->newObject(modUserGroup::class, ['name' => PREFIX . $key]), "group {$key}");
    $groups[$key] = (int) $group->get('id');
    grant($modx, modAccessContext::class, 'mgr', $groups[$key], $policy, null);
}
// editor gets web access, as a real content editor has; editnoweb does not,
// which is the whole point of it.
grant($modx, modAccessContext::class, 'web', $groups['editor'], $loadView, null);

$users = [
    'loadonly'  => makeUser($modx, PREFIX . 'loadonly', $groups['loadonly'], $member),
    'editor'    => makeUser($modx, PREFIX . 'editor', $groups['editor'], $member),
    'editnoweb' => makeUser($modx, PREFIX . 'editnoweb', $groups['editnoweb'], $member),
    'admin'     => makeUser($modx, PREFIX . 'admin', $adminGroupId, $superUser),
];

$parent = $modx->newObject(modDocument::class);
$parent->fromArray([
    'pagetitle'   => 'modxmcp permission parent',
    'alias'       => ALIAS . 'parent',
    'published'   => 0,
    'hidemenu'    => 1,
    'context_key' => 'web',
    'parent'      => 0,
    'template'    => 0,
]);
saved($parent, 'parent resource');

$rg = saved($modx->newObject(modResourceGroup::class, ['name' => PREFIX . 'rg']), 'resource group');
grant($modx, modAccessResourceGroup::class, (string) $rg->get('id'), $groups['editor'], $loadOnly);
grant($modx, modAccessResourceGroup::class, (string) $rg->get('id'), $adminGroupId, $resource);

$probe = $modx->newObject(modDocument::class);
$probe->fromArray([
    'pagetitle'   => 'modxmcp permission probe',
    'alias'       => ALIAS . 'probe',
    'published'   => 0,
    'hidemenu'    => 1,
    'searchable'  => 1,
    'context_key' => 'web',
    'parent'      => 0,
    'template'    => 0,
    'content'     => 'modxmcp permission probe body',
]);
saved($probe, 'probe resource');
$link = $modx->newObject(modResourceGroupResource::class);
$link->fromArray(['document_group' => $rg->get('id'), 'document' => $probe->get('id')], '', true, true);
saved($link, 'probe resource group link');

$cat = saved($modx->newObject(modCategory::class, ['category' => PREFIX . 'cat', 'parent' => 0]), 'category');
grant($modx, modAccessCategory::class, (string) $cat->get('id'), $groups['editor'], $loadOnly);
grant($modx, modAccessCategory::class, (string) $cat->get('id'), $adminGroupId, $element);

$template = $modx->newObject(modTemplate::class);
$template->fromArray(['templatename' => PREFIX . 'template', 'category' => $cat->get('id'), 'content' => 'modxmcp permission probe template']);
saved($template, 'probe template');
$chunk = $modx->newObject(modChunk::class);
$chunk->fromArray(['name' => PREFIX . 'chunk', 'category' => $cat->get('id'), 'snippet' => 'modxmcp permission probe chunk']);
saved($chunk, 'probe chunk');

$service = new \MODXMCP\Auth\TokenService();
$tokens  = [];
foreach ($users as $key => $user) {
    // write:media only for the admin, so dev/tools-test.php can run its
    // file_upload suite with the admin token; the restricted users never get it.
    $scopes = ['read', 'write:content', 'write:elements'];
    if ($key === 'admin') {
        $scopes[] = 'write:media';
    }
    $issued = $service->issue($modx, PREFIX . $key, (int) $user->get('id'), $scopes);
    $tokens[$key] = ['id' => $issued['id'], 'token' => $issued['token'], 'username' => $user->get('username')];
}

$modx->cacheManager->refresh();

$fixture = [
    'tokens' => $tokens,
    'ids'    => [
        'probe_resource' => (int) $probe->get('id'),
        'parent'         => (int) $parent->get('id'),
        'probe_template' => (int) $template->get('id'),
        'probe_chunk'    => (int) $chunk->get('id'),
        'resource_group' => (int) $rg->get('id'),
        'category'       => (int) $cat->get('id'),
    ],
];
if (file_put_contents($out, json_encode($fixture, JSON_PRETTY_PRINT)) === false) {
    fail("could not write {$out}");
}
chmod($out, 0600);

printf("  users: %s\n", implode(', ', array_map(fn($u) => $u->get('username') . '=' . $u->get('id'), $users)));
printf("  probe resource %d in resource group %d; template %d and chunk %d in category %d\n",
    $probe->get('id'), $rg->get('id'), $template->get('id'), $chunk->get('id'), $cat->get('id'));
printf("  %d tokens written to %s (0600)\n", count($tokens), $out);
