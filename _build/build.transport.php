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
const PKG_VERSION = '1.1.0';
const PKG_RELEASE = 'pl';

$root = dirname(__DIR__) . '/';

// ---------------------------------------------------------------------------
// Version agreement, checked before anything is built.
//
// Three numbers describe this package and nothing kept them in step: the
// constants above, Server::VERSION (which every MCP client reads as
// serverInfo.version and which dev/deploy.sh uses as its drift check), and the
// newest changelog heading. They drifted for five releases -- 1.0.0-beta5
// shipped reporting 0.4.0 -- because agreeing was a manual habit rather than a
// build step.
//
// A mismatch fails the build. Shipping a package that misreports its own
// version is not detectable afterwards by looking at the zip.
// ---------------------------------------------------------------------------

$serverPhp = @file_get_contents($root . 'core/components/modxmcp/src/Server.php');
if ($serverPhp === false || !preg_match("/VERSION\s*=\s*'([^']+)'/", $serverPhp, $m)) {
    exit("Could not read Server::VERSION. Refusing to build a package that cannot verify itself.\n");
}

if ($m[1] !== PKG_VERSION) {
    exit(sprintf(
        "Version mismatch: Server::VERSION is '%s' but PKG_VERSION is '%s'.\n"
        . "Clients read Server::VERSION as serverInfo.version, so this package would report\n"
        . "the wrong version to every caller. Set both, then build.\n",
        $m[1],
        PKG_VERSION
    ));
}

$heading   = PKG_VERSION . '-' . PKG_RELEASE;
$changelog = @file_get_contents($root . 'core/components/modxmcp/docs/changelog.txt');
if ($changelog === false || !preg_match('/^' . preg_quote($heading, '/') . '$/m', $changelog)) {
    exit(sprintf(
        "The changelog has no '%s' section. Add it, then build.\n",
        $heading
    ));
}

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
    // 8.2, not 8.1: the support traits declare constants, which PHP only
    // allows in a trait from 8.2. This has been true of ExcerptSupport and
    // ObjectSupport since before it was written down, so 8.1 was never a
    // version this package actually ran on -- search and the generic object
    // tools would fatal at include time.
    'requires'  => ['php' => '>=8.2', 'modx' => '>=3.0'],
]);

$modx->log(modX::LOG_LEVEL_INFO, 'packing...');
$builder->pack();

// The fingerprint of what was just packaged. Rebuilding the same signature is
// legitimate and normal during a release; this is how you tell the resulting
// zip apart from the one it replaced, and how you confirm afterwards that the
// install actually took. modxmcp_site_info reports the same value for the code
// running on a site.
require_once $root . 'core/components/modxmcp/src/Server.php';
require_once $root . 'core/components/modxmcp/src/Build.php';
$build = MODXMCP\Build::describe();

$modx->log(modX::LOG_LEVEL_INFO, sprintf(
    'built %s-%s-%s.transport.zip (build %s, %d source files)',
    PKG_NAME,
    PKG_VERSION,
    PKG_RELEASE,
    $build['build'],
    $build['files']
));

$modx->log(modX::LOG_LEVEL_INFO, sprintf(
    'install it with: php dev/package-install.php %s-%s-%s',
    PKG_NAME,
    PKG_VERSION,
    PKG_RELEASE
));
