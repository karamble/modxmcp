<?php
/**
 * Installs the built transport package through MODX's own package manager and
 * verifies the result.
 *
 * Building a zip proves nothing on its own. What matters is that a real install
 * lands every piece: files, tables, settings at their safe defaults, the
 * snippet, the menu, the event, and an endpoint resource that actually answers.
 *
 *   php dev/package-install-test.php install
 *   php dev/package-install-test.php verify
 *   php dev/package-install-test.php uninstall
 */

error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
define('MODX_API_MODE', true);
require_once 'index.php';

use MODX\Revolution\modX;
use MODX\Revolution\modUser;
use MODX\Revolution\modSnippet;
use MODX\Revolution\modMenu;
use MODX\Revolution\modEvent;
use MODX\Revolution\modResource;
use MODX\Revolution\modNamespace;
use MODX\Revolution\modSystemSetting;
use MODX\Revolution\Transport\modTransportPackage;

$modx = new modX();
$modx->initialize('mgr');
$modx->setLogLevel(modX::LOG_LEVEL_ERROR);
$_SESSION = [];
$sudo = $modx->getObject(modUser::class, ['sudo' => 1]);
$modx->user = $sudo;
$modx->user->getAttributes([], 'mgr', true);

$action = $argv[1] ?? 'verify';
$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %-52s %s\n", $ok ? 'PASS' : 'FAIL', $label, $detail);
}

function signature(modX $modx): ?string
{
    $package = $modx->getObject(modTransportPackage::class, ['signature:LIKE' => 'modxmcp%']);
    return $package ? $package->get('signature') : null;
}

if ($action === 'install') {
    $response = $modx->runProcessor('Workspace/Packages/ScanLocal');
    echo "scan: " . ($response && !$response->isError() ? "ok" : "failed") . "\n";

    $signature = signature($modx);
    if (!$signature) {
        exit("FATAL: package not registered after scan\n");
    }
    echo "found: {$signature}\n";

    $package = $modx->getObject(modTransportPackage::class, ['signature' => $signature]);
    $response = $modx->runProcessor('Workspace/Packages/Install', ['signature' => $signature]);
    $out = $response ? $response->getResponse() : null;
    echo "install: " . (is_array($out) && !empty($out['success']) ? 'SUCCESS' : 'FAILED') . "\n";
    if (is_array($out) && empty($out['success'])) {
        echo "  message: " . ($out['message'] ?? '?') . "\n";
    }
    exit;
}

if ($action === 'uninstall') {
    $signature = signature($modx);
    if ($signature) {
        $response = $modx->runProcessor('Workspace/Packages/Uninstall', ['signature' => $signature]);
        $out = $response ? $response->getResponse() : null;
        echo "uninstall: " . (is_array($out) && !empty($out['success']) ? 'SUCCESS' : 'FAILED') . "\n";
    }
    exit;
}

// ------------------------------------------------------------------- verify

echo "package install result\n" . str_repeat('=', 74) . "\n";

$core   = MODX_CORE_PATH . 'components/modxmcp/';
$assets = MODX_ASSETS_PATH . 'components/modxmcp/';

check('core files installed', is_dir($core) && is_readable($core . 'src/Server.php'));
check('assets installed', is_dir($assets) && is_readable($assets . 'mgr/modxmcp.js'));
check('ships no vendor directory', !is_dir($core . 'vendor'), 'zero third-party deps');
check('namespace registered', (bool) $modx->getObject(modNamespace::class, ['name' => 'modxmcp']));
check('snippet installed', (bool) $modx->getObject(modSnippet::class, ['name' => 'modxmcp']));
check('menu installed', (bool) $modx->getObject(modMenu::class, ['text' => 'modxmcp']));
check('event registered', (bool) $modx->getObject(modEvent::class, ['name' => 'OnMCPRegisterTools']));

// tables
$prefix = $modx->getOption('table_prefix');
foreach (['modxmcp_token', 'modxmcp_audit'] as $table) {
    $stmt = $modx->prepare("SHOW TABLES LIKE '{$prefix}{$table}'");
    $stmt->execute();
    check("table {$table} created", (bool) $stmt->fetchColumn());
}

// settings, and their safe defaults
$expected = [
    'modxmcp.enabled'                => '0',
    'modxmcp.read_class_allowlist'   => '',
    'modxmcp.write_class_allowlist'  => '',
    'modxmcp.log_arguments'          => '0',
];
foreach ($expected as $key => $default) {
    $setting = $modx->getObject(modSystemSetting::class, ['key' => $key]);
    check("setting {$key} present", (bool) $setting);
    if ($setting) {
        check("  defaults to " . ($default === '' ? '(empty)' : $default),
            (string) $setting->get('value') === $default,
            'installs closed, not open');
    }
}

// endpoint resource
$endpoint = $modx->getObject(modResource::class, ['content:LIKE' => '%[[!modxmcp%', 'deleted' => 0]);
check('endpoint resource created', (bool) $endpoint, $endpoint ? $endpoint->get('uri') : '');
if ($endpoint) {
    check('  endpoint alias is unguessable',
        (bool) preg_match('/^mcp-[a-f0-9]{12}$/', (string) $endpoint->get('alias')),
        (string) $endpoint->get('alias'));
    check('  endpoint uses the blank template', (int) $endpoint->get('template') === 0);
    check('  endpoint is uncacheable', (int) $endpoint->get('cacheable') === 0);
    check('  endpoint is not searchable', (int) $endpoint->get('searchable') === 0);
    check('  endpoint is hidden from menus', (int) $endpoint->get('hidemenu') === 1);
}

echo str_repeat('=', 74) . "\n{$pass} passed, {$fail} failed\n";
if ($endpoint) {
    echo "endpoint: " . rtrim((string) $modx->getOption('site_url'), '/') . '/' . $endpoint->get('uri') . "\n";
}
exit($fail === 0 ? 0 : 1);
