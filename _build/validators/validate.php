<?php
/**
 * Pre-install gate.
 *
 * Returning false here aborts the install with a message the user can act on.
 * That matters more than usual for this package: without the check, a site on
 * an older PHP would install cleanly and then fatal on first use, and the
 * symptom (a white page from an endpoint) points nowhere near the cause.
 *
 * @var \MODX\Revolution\Transport\modTransportPackage $object
 * @var array $options
 */

use MODX\Revolution\modX;

/** @var modX $modx */
$modx = $object->xpdo;

$minimumPhp  = '8.1.0';
$minimumModx = '3.0.0';
$ok          = true;

if (version_compare(PHP_VERSION, $minimumPhp, '<')) {
    $modx->log(modX::LOG_LEVEL_ERROR, sprintf(
        'modxmcp requires PHP %s or newer; this server runs PHP %s. Installation aborted.',
        $minimumPhp,
        PHP_VERSION
    ));
    $ok = false;
}

$version = $modx->getVersionData();
$current = $version['full_version'] ?? '0';
if (version_compare($current, $minimumModx, '<')) {
    $modx->log(modX::LOG_LEVEL_ERROR, sprintf(
        'modxmcp requires MODX %s or newer; this site runs %s. Installation aborted.',
        $minimumModx,
        $current
    ));
    $ok = false;
}

// The endpoint bootstraps through MODX's own autoloader. Its absence means a
// non-standard or damaged install, and every tool would fail on first call.
if (!is_readable(MODX_CORE_PATH . 'vendor/autoload.php')) {
    $modx->log(modX::LOG_LEVEL_ERROR,
        'modxmcp could not find MODX core\'s vendor/autoload.php. Installation aborted.');
    $ok = false;
}

return $ok;
