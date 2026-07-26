<?php
/**
 * Creates plugins bound to OnMCPRegisterTools, to prove the extension point.
 *
 *   php dev/adapter-test-setup.php good     a plugin that registers a tool
 *   php dev/adapter-test-setup.php bad      a plugin that throws
 *   php dev/adapter-test-setup.php cleanup
 */

error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
define('MODX_API_MODE', true);
require_once 'index.php';

use MODX\Revolution\modX;
use MODX\Revolution\modPlugin;
use MODX\Revolution\modPluginEvent;
use MODX\Revolution\modUser;

$modx = new modX();
$modx->initialize('mgr');
$modx->setLogLevel(modX::LOG_LEVEL_ERROR);
$_SESSION = [];
$sudo = $modx->getObject(modUser::class, ['sudo' => 1]);
$modx->user = $sudo;
$modx->user->getAttributes([], 'mgr', true);

const PLUGIN_NAME = 'modxmcpThirdPartyProbe';

function removePlugin(modX $modx): void
{
    if ($plugin = $modx->getObject(modPlugin::class, ['name' => PLUGIN_NAME])) {
        foreach ($modx->getCollection(modPluginEvent::class, ['pluginid' => $plugin->get('id')]) as $binding) {
            $binding->remove();
        }
        $plugin->remove();
    }
}

$mode = $argv[1] ?? 'good';

removePlugin($modx);

if ($mode === 'cleanup') {
    $modx->cacheManager->refresh();
    echo "probe plugin removed\n";
    exit;
}

// A third-party extra would ship a real class; an anonymous class keeps the
// probe self-contained while exercising the same contract.
$good = <<<'PHP'
$registry = $scriptProperties['registry'] ?? null;
if (!$registry) { return; }
$registry->register(new class implements \MODXMCP\Registry\ToolInterface {
    public function name(): string { return 'thirdparty_probe'; }
    public function requiredScope(): string { return 'read'; }
    public function definition(): array {
        return [
            'name' => $this->name(),
            'title' => 'Third party probe',
            'description' => 'Registered by a plugin on OnMCPRegisterTools, to prove that a '
                . 'third-party extra can contribute tools without modifying modxmcp.',
            'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
        ];
    }
    public function call(\MODX\Revolution\modX $modx, array $arguments): array {
        return ['registered_by' => 'plugin', 'ok' => true];
    }
});
PHP;

$bad = <<<'PHP'
throw new \RuntimeException('deliberate failure from a third-party plugin');
PHP;

$plugin = $modx->newObject(modPlugin::class);
$plugin->set('name', PLUGIN_NAME);
$plugin->set('description', 'modxmcp test probe. Safe to delete.');
$plugin->set('plugincode', $mode === 'bad' ? $bad : $good);
$plugin->save();

$binding = $modx->newObject(modPluginEvent::class);
$binding->fromArray([
    'pluginid'    => $plugin->get('id'),
    'event'       => 'OnMCPRegisterTools',
    'priority'    => 0,
    'propertyset' => 0,
], '', true, true);
$binding->save();

$modx->cacheManager->refresh();

echo "probe plugin installed in '{$mode}' mode (id " . $plugin->get('id') . ")\n";
