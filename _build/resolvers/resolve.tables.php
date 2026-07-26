<?php
/**
 * Create the modxmcp tables on install; leave them alone on uninstall.
 *
 * Uninstall deliberately does NOT drop them. modxmcp_audit is a security record
 * of everything an API token did, and destroying that because someone removed
 * the package to try a different version is the wrong default. The tables are
 * small, and dropping them is a documented manual step.
 *
 * @var \xPDO\Transport\xPDOObjectVehicle $object
 * @var array $options
 */

use MODX\Revolution\modX;
use xPDO\Transport\xPDOTransport;

if ($object->xpdo === null) {
    return false;
}

/** @var modX $modx */
$modx   = $object->xpdo;
$action = $options[xPDOTransport::PACKAGE_ACTION] ?? '';

if (!in_array($action, [xPDOTransport::ACTION_INSTALL, xPDOTransport::ACTION_UPGRADE], true)) {
    return true;
}

$modx->addPackage(
    'MODXMCP\\Model',
    $modx->getOption('core_path', null, MODX_CORE_PATH) . 'components/modxmcp/src/',
    null,
    'MODXMCP\\'
);

$manager = $modx->getManager();

foreach ([\MODXMCP\Model\ModxmcpToken::class, \MODXMCP\Model\ModxmcpAudit::class] as $class) {
    // Idempotent: creating an existing container is a no-op, and on upgrade this
    // adds any table that a newer version introduced.
    $manager->createObjectContainer($class);
}

return true;
