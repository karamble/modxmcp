<?php
/**
 * Install a modxmcp transport package by signature, correctly.
 *
 *   php dev/package-install.php modxmcp-1.0.0-pl [/path/to/modx]
 *
 * Run on the target server. Replaces dev/package-install-test.php, which
 * resolved whichever modxmcp package it found first and reverted a site to
 * beta2 during the 1.0.0 release.
 *
 * Two things make installing a rebuilt package harder than it looks, and both
 * fail silently rather than erroring:
 *
 * 1. Workspace/Packages/ScanLocal skips any signature already present in
 *    modx_transport_packages (ScanLocal.php:66-71, `continue`). A rebuilt
 *    package keeps its signature, so the row surviving from the previous build
 *    is reused, along with its cached `attributes` and `state`.
 *
 * 2. modTransportPackage::getTransport() only unzips when the extracted
 *    directory is absent (modTransportPackage.php:244-245):
 *
 *        $state = is_dir($packageDir . $targetDir)
 *            ? $this->get('state') : xPDOTransport::STATE_PACKED;
 *
 *    With core/packages/<signature>/ left over from the previous build, the new
 *    zip is never opened and the previous build is installed instead.
 *
 * So a same-signature reinstall has to remove the row and the extracted
 * directory first. That is all this script does that the Manager does not, and
 * it is why "just install it again" quietly does nothing.
 *
 * Bumping the version each rebuild would also avoid this, at the cost of a
 * version number per iteration. The build fingerprint reported at the end is
 * the cheaper answer: it tells you which build is live without spending one.
 */

use MODX\Revolution\modX;
use MODX\Revolution\Transport\modTransportPackage;

$signature = $argv[1] ?? '';
if ($signature === '') {
    exit("usage: php dev/package-install.php <signature> [modx-base-path]\n"
        . "   eg: php dev/package-install.php modxmcp-1.0.0-pl\n");
}

$base = rtrim($argv[2] ?? dirname(__DIR__, 4), '/') . '/';
if (!is_readable($base . 'config.core.php')) {
    exit("No config.core.php under {$base}. Pass the MODX base path as the second argument.\n");
}

require_once $base . 'config.core.php';
require_once MODX_CORE_PATH . 'vendor/autoload.php';

$modx = new modX();
$modx->initialize('mgr');
$modx->setLogLevel(modX::LOG_LEVEL_INFO);
$modx->setLogTarget('ECHO');
$modx->getService('error', 'error.modError');
$modx->lexicon->load('workspaces');

$packageDir = MODX_CORE_PATH . 'packages/';
$zip        = $packageDir . $signature . '.transport.zip';

if (!is_readable($zip)) {
    echo "Not found: {$zip}\n\n";
    echo "Available:\n";
    foreach (glob($packageDir . '*.transport.zip') ?: [] as $file) {
        echo '  ' . basename($file, '.transport.zip') . "\n";
    }
    exit(1);
}

echo "installing {$signature}\n";
echo '  zip     ' . date('Y-m-d H:i:s', (int) filemtime($zip))
    . ', ' . number_format(filesize($zip) / 1024, 0) . " KB\n";

// --- clear anything that would shadow the zip -------------------------------

$existing = $modx->getObject(modTransportPackage::class, ['signature' => $signature]);
if ($existing) {
    echo "  removing stale transport_packages row (state {$existing->get('state')})\n";
    // remove(), not uninstall(). Uninstalling would run the package's uninstall
    // path and drop what the install is about to recreate.
    $existing->remove();
}

$extracted = $packageDir . $signature;
if (is_dir($extracted)) {
    echo "  removing stale extracted directory\n";
    $modx->getCacheManager()->deleteTree($extracted, true, false, []);
}

// --- register and install ---------------------------------------------------

$scan = $modx->runProcessor('Workspace/Packages/ScanLocal');
if (!$scan || $scan->isError()) {
    exit('  ScanLocal failed: ' . ($scan ? $scan->getMessage() : 'no response') . "\n");
}

$install = $modx->runProcessor('Workspace/Packages/Install', ['signature' => $signature]);
if (!$install || $install->isError()) {
    exit('  Install failed: ' . ($install ? $install->getMessage() : 'no response') . "\n");
}

$modx->cacheManager->refresh();

// --- report what is now actually running ------------------------------------

// Read through the installed files rather than the repo, so this reports the
// site's state and not the state of whatever tree the script was run from.
$installedBuild = MODX_CORE_PATH . 'components/modxmcp/src/Build.php';
if (is_readable($installedBuild)) {
    require_once MODX_CORE_PATH . 'components/modxmcp/src/Server.php';
    require_once $installedBuild;
    $build = MODXMCP\Build::describe();
    echo sprintf(
        "  installed: %s, build %s (%d source files)\n",
        $build['version'],
        $build['build'],
        $build['files']
    );
    echo "  compare this with the build reported when the zip was packed.\n";
} else {
    echo "  WARNING: installed but core/components/modxmcp/src/Build.php is missing.\n";
}
