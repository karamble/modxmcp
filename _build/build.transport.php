<?php
/**
 * Builds the modxmcp transport package.
 *
 *   cp _build/build.config.sample.php _build/build.config.php   (and edit)
 *   php _build/build.transport.php
 *
 * Produces _packages/modxmcp-<version>-<release>.transport.zip
 */

use MODX\Revolution\modCategory;
use MODX\Revolution\modMenu;
use MODX\Revolution\modSnippet;
use MODX\Revolution\modX;
use MODX\Revolution\Transport\modPackageBuilder;
use xPDO\Transport\xPDOTransport;

const PKG_NAME    = 'modxmcp';
const PKG_VERSION = '1.0.0';
const PKG_RELEASE = 'beta1';

$root = dirname(__DIR__) . '/';

if (!is_readable(__DIR__ . '/build.config.php')) {
    exit("Copy _build/build.config.sample.php to _build/build.config.php and set the paths.\n");
}
require_once __DIR__ . '/build.config.php';
require_once MODX_CORE_PATH . 'vendor/autoload.php';

$modx = new modX();
$modx->initialize('mgr');
$modx->setLogLevel(modX::LOG_LEVEL_INFO);
$modx->setLogTarget('ECHO');
$modx->loadClass(modPackageBuilder::class);

$sources = [
    'core'       => $root . 'core/components/modxmcp',
    'assets'     => $root . 'assets/components/modxmcp',
    'data'       => $root . '_build/data/',
    'resolvers'  => $root . '_build/resolvers/',
    'validators' => $root . '_build/validators/',
    'docs'       => $root . 'core/components/modxmcp/docs/',
    'snippet'    => $root . 'core/components/modxmcp/elements/snippets/modxmcp.snippet.php',
];

$builder = new modPackageBuilder($modx);
$builder->createPackage(PKG_NAME, PKG_VERSION, PKG_RELEASE);
$builder->registerNamespace(
    PKG_NAME,
    false,
    true,
    '{core_path}components/modxmcp/',
    '{assets_path}components/modxmcp/'
);

// ---------------------------------------------------------- category + snippet

$category = $modx->newObject(modCategory::class);
$category->set('id', 1);
$category->set('category', PKG_NAME);

$snippetCode = file_get_contents($sources['snippet']);
// MODX stores element bodies without the opening tag.
$snippetCode = preg_replace('/^\s*<\?php\s*/', '', (string) $snippetCode);

$snippet = $modx->newObject(modSnippet::class);
$snippet->fromArray([
    'id'          => 1,
    'name'        => 'modxmcp',
    'description' => 'MCP endpoint. Call uncached from a blank-template, uncacheable resource.',
    'snippet'     => $snippetCode,
], '', true, true);

// addMany() takes its first argument by reference, so this must be a variable
// rather than a literal. Same trap as xPDOCacheManager::set().
$snippets = [$snippet];
$category->addMany($snippets, 'Snippets');

$categoryVehicle = $builder->createVehicle($category, [
    xPDOTransport::UNIQUE_KEY                 => 'category',
    xPDOTransport::PRESERVE_KEYS              => false,
    xPDOTransport::UPDATE_OBJECT              => true,
    xPDOTransport::RELATED_OBJECTS            => true,
    xPDOTransport::RELATED_OBJECT_ATTRIBUTES  => [
        'Snippets' => [
            xPDOTransport::PRESERVE_KEYS => false,
            xPDOTransport::UPDATE_OBJECT => true,
            xPDOTransport::UNIQUE_KEY    => 'name',
        ],
    ],
]);

// Nothing may install if the environment cannot run it.
$categoryVehicle->validate('php', ['source' => $sources['validators'] . 'validate.php']);

$categoryVehicle->resolve('file', [
    'source' => $sources['core'],
    'target' => "return MODX_CORE_PATH . 'components/';",
]);
$categoryVehicle->resolve('file', [
    'source' => $sources['assets'],
    'target' => "return MODX_ASSETS_PATH . 'components/';",
]);
$categoryVehicle->resolve('php', ['source' => $sources['resolvers'] . 'resolve.tables.php']);
$categoryVehicle->resolve('php', ['source' => $sources['resolvers'] . 'resolve.endpoint.php']);

$builder->putVehicle($categoryVehicle);

// ------------------------------------------------------------------ settings

$settings = include $sources['data'] . 'transport.settings.php';
foreach ($settings as $setting) {
    $builder->putVehicle($builder->createVehicle($setting, [
        xPDOTransport::UNIQUE_KEY    => 'key',
        xPDOTransport::PRESERVE_KEYS => true,
        // Never on upgrade. These settings are the security posture of the
        // install: silently resetting modxmcp.enabled or an allowlist because
        // someone updated the package would be indefensible.
        xPDOTransport::UPDATE_OBJECT => false,
    ]));
}
$modx->log(modX::LOG_LEVEL_INFO, 'packaged ' . count($settings) . ' system settings');

// ---------------------------------------------------------------------- menu

$menu = include $sources['data'] . 'transport.menu.php';
$builder->putVehicle($builder->createVehicle($menu, [
    xPDOTransport::PRESERVE_KEYS => true,
    xPDOTransport::UPDATE_OBJECT => true,
    xPDOTransport::UNIQUE_KEY    => 'text',
]));

// ---------------------------------------------------------------- attributes

$read = static function (string $file): string {
    return is_readable($file) ? (string) file_get_contents($file) : '';
};

$builder->setPackageAttributes([
    'license'   => $read($sources['docs'] . 'license.txt'),
    'readme'    => $read($sources['docs'] . 'readme.txt'),
    'changelog' => $read($sources['docs'] . 'changelog.txt'),
    'requires'  => ['php' => '>=8.1', 'modx' => '>=3.0'],
]);

$modx->log(modX::LOG_LEVEL_INFO, 'packing...');
$builder->pack();

$modx->log(modX::LOG_LEVEL_INFO, sprintf(
    'built %s-%s-%s.transport.zip',
    PKG_NAME,
    PKG_VERSION,
    PKG_RELEASE
));
