<?php
/**
 * Development installer for modxmcp.
 *
 * Creates tables, system settings, the endpoint snippet and resource, and
 * issues a first token. This is the behaviour the M6 transport resolvers will
 * perform; keeping it here first means the install path gets exercised on every
 * test cycle rather than only at packaging time.
 *
 *   php dev/install.php                 install (or re-run safely)
 *   php dev/install.php issue <name>    issue an additional token
 *   php dev/install.php uninstall       remove everything it created
 *
 * Run from a MODX web root.
 */

error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
define('MODX_API_MODE', true);
require_once 'index.php';

use MODX\Revolution\modX;
use MODX\Revolution\modUser;
use MODX\Revolution\modSnippet;
use MODX\Revolution\modResource;
use MODX\Revolution\modSystemSetting;

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

use MODXMCP\Auth\TokenService;
use MODXMCP\Model\ModxmcpToken;
use MODXMCP\Model\ModxmcpAudit;

const SNIPPET_NAME = 'modxmcp';
const ENDPOINT_ALIAS = 'modxmcp-endpoint';

$action = $argv[1] ?? 'install';

// ------------------------------------------------------------------ uninstall

if ($action === 'uninstall') {
    foreach ([[modSnippet::class, ['name' => SNIPPET_NAME]], [modResource::class, ['alias' => ENDPOINT_ALIAS]]] as [$cls, $crit]) {
        if ($o = $modx->getObject($cls, $crit)) {
            $o->remove();
            echo "removed {$cls}\n";
        }
    }
    foreach (['modxmcp.enabled', 'modxmcp.log_arguments', 'modxmcp.trusted_proxy_header', 'modxmcp.audit_retention_days'] as $key) {
        if ($s = $modx->getObject(modSystemSetting::class, ['key' => $key])) {
            $s->remove();
            echo "removed setting {$key}\n";
        }
    }
    $manager = $modx->getManager();
    foreach ([ModxmcpAudit::class, ModxmcpToken::class] as $cls) {
        $manager->removeObjectContainer($cls);
        echo "dropped table for {$cls}\n";
    }
    $modx->cacheManager->refresh();
    echo "done\n";
    exit;
}

// ------------------------------------------------------------------- tables

$manager = $modx->getManager();
foreach ([ModxmcpToken::class, ModxmcpAudit::class] as $cls) {
    $manager->createObjectContainer($cls);
    echo "table ready: {$cls}\n";
}

// --------------------------------------------------------------- issue only

if ($action === 'issue') {
    $name = $argv[2] ?? 'modxmcp token';
    $svc  = new TokenService();
    $res  = $svc->issue($modx, $name, (int) $sudo->get('id'), ['read', 'write:content', 'write:elements']);
    echo "\ntoken id {$res['id']}\n{$res['token']}\n";
    exit;
}

// ----------------------------------------------------------------- settings

$settings = [
    'modxmcp.enabled' => [
        'value' => '0',
        'xtype' => 'combo-boolean',
        'area'  => 'modxmcp',
    ],
    'modxmcp.log_arguments' => [
        'value' => '0',
        'xtype' => 'combo-boolean',
        'area'  => 'modxmcp',
    ],
    'modxmcp.trusted_proxy_header' => [
        'value' => '',
        'xtype' => 'textfield',
        'area'  => 'modxmcp',
    ],
    'modxmcp.audit_retention_days' => [
        'value' => '90',
        'xtype' => 'textfield',
        'area'  => 'modxmcp',
    ],
    // Generic object access is opt-in per class. Both default to empty: xPDO has
    // no permission model, so for an arbitrary extra class these settings are
    // the only access control, and a permissive default would expose whatever
    // that extra happens to store.
    'modxmcp.read_class_allowlist' => [
        'value' => '',
        'xtype' => 'textarea',
        'area'  => 'modxmcp.generic_access',
    ],
    'modxmcp.write_class_allowlist' => [
        'value' => '',
        'xtype' => 'textarea',
        'area'  => 'modxmcp.generic_access',
    ],
    'modxmcp.discovery_cache_seconds' => [
        'value' => '300',
        'xtype' => 'textfield',
        'area'  => 'modxmcp',
    ],
];

foreach ($settings as $key => $def) {
    $setting = $modx->getObject(modSystemSetting::class, ['key' => $key]);
    if (!$setting) {
        $setting = $modx->newObject(modSystemSetting::class);
        $setting->set('key', $key);
        $setting->set('value', $def['value']);   // only on create: never stomp a chosen value
    }
    $setting->set('xtype', $def['xtype']);
    $setting->set('namespace', 'modxmcp');
    $setting->set('area', $def['area']);
    $setting->save();
    echo "setting: {$key}\n";
}

// -------------------------------------------------------- namespace + menu

use MODX\Revolution\modNamespace;
use MODX\Revolution\modMenu;

$ns = $modx->getObject(modNamespace::class, ['name' => 'modxmcp']) ?: $modx->newObject(modNamespace::class);
$ns->fromArray([
    'name'       => 'modxmcp',
    'path'       => '{core_path}components/modxmcp/',
    'assets_path'=> '{assets_path}components/modxmcp/',
], '', true, true);
$ns->save();
echo "namespace: modxmcp\n";

$menu = $modx->getObject(modMenu::class, ['text' => 'modxmcp']) ?: $modx->newObject(modMenu::class);
$menu->fromArray([
    'text'        => 'modxmcp',
    'parent'      => 'components',
    'description' => 'modxmcp.menu.desc',
    'action'      => 'home',
    'namespace'   => 'modxmcp',
    'menuindex'   => 0,
], '', true, true);
$menu->save();
echo "menu: Components > MCP Server\n";

// ------------------------------------------------------------------ snippet

$code = file_get_contents(MODX_CORE_PATH . 'components/modxmcp/elements/snippets/modxmcp.snippet.php');
// MODX stores snippet bodies without the opening PHP tag.
$code = preg_replace('/^\s*<\?php\s*/', '', (string) $code);

$snippet = $modx->getObject(modSnippet::class, ['name' => SNIPPET_NAME]) ?: $modx->newObject(modSnippet::class);
$snippet->set('name', SNIPPET_NAME);
$snippet->set('description', 'MCP endpoint. Call uncached from a blank-template, uncacheable resource.');
$snippet->set('snippet', $code);
$snippet->save();
echo "snippet: " . SNIPPET_NAME . " (id " . $snippet->get('id') . ")\n";

// ----------------------------------------------------------------- resource

$res = $modx->getObject(modResource::class, ['alias' => ENDPOINT_ALIAS]) ?: $modx->newObject(modResource::class);
$res->fromArray([
    'pagetitle'    => 'modxmcp endpoint',
    'description'  => 'MCP endpoint. Do not link to this or add it to menus.',
    'alias'        => ENDPOINT_ALIAS,
    'parent'       => 0,
    'template'     => 0,
    'published'    => 1,
    'hidemenu'     => 1,
    'searchable'   => 0,
    'cacheable'    => 0,
    'richtext'     => 0,
    'context_key'  => 'web',
    'content_type' => 1,
    'content'      => '[[!' . SNIPPET_NAME . ']]',
]);
$res->save();
echo "resource: " . ENDPOINT_ALIAS . " (id " . $res->get('id') . ")\n";

$modx->cacheManager->refresh();

// -------------------------------------------------------------- first token

$existing = $modx->getCount(ModxmcpToken::class);
if ($existing === 0) {
    $svc = new TokenService();
    $out = $svc->issue($modx, 'initial development token', (int) $sudo->get('id'),
        ['read', 'write:content', 'write:elements']);
    echo "\ntoken id {$out['id']} (shown once):\n{$out['token']}\n";
} else {
    echo "\n{$existing} token(s) already exist; not issuing another\n";
}

echo "\nendpoint: " . $modx->getOption('site_url') . $res->get('uri') . "\n";
echo "NOTE: modxmcp.enabled defaults to 0. Set it to 1 to accept requests.\n";
