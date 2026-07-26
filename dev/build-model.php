<?php
/**
 * Generates the xPDO model classes from schema/modxmcp.mysql.schema.xml.
 *
 * Run on a server with MODX available, from a MODX web root:
 *   php dev/build-model.php /absolute/path/to/core/components/modxmcp
 *
 * Uses MODX's own generator rather than hand-written map files, so the output
 * matches what every other extra ships and stays regenerable when the schema
 * changes.
 */

error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
define('MODX_API_MODE', true);
require_once 'index.php';

use MODX\Revolution\modX;

$base = rtrim($argv[1] ?? '', '/');
if ($base === '' || !is_dir($base)) {
    exit("usage: php dev/build-model.php /path/to/core/components/modxmcp\n");
}

$schema = $base . '/schema/modxmcp.mysql.schema.xml';
if (!is_readable($schema)) {
    exit("schema not readable: {$schema}\n");
}

$modx = new modX();
$modx->initialize('mgr');
$modx->setLogLevel(modX::LOG_LEVEL_INFO);
$modx->setLogTarget('ECHO');

$manager   = $modx->getManager();
$generator = $manager->getGenerator();

// namespacePrefix MUST be passed as an option. xPDOGenerator reads it from
// $options (xPDOGenerator.php:249), and an attribute of the same name on the
// schema's <model> element is silently ignored. Without it the classes land in
// src/MODXMCP/Model/ rather than src/Model/, breaking the autoloader mapping.
$generator->parseSchema($schema, $base . '/src/', ['namespacePrefix' => 'MODXMCP']);

echo "\ngenerated into {$base}/src/Model/\n";
foreach (glob($base . '/src/Model/*.php') ?: [] as $f) {
    echo '  ' . basename($f) . "\n";
}
foreach (glob($base . '/src/Model/mysql/*.php') ?: [] as $f) {
    echo '  mysql/' . basename($f) . "\n";
}
