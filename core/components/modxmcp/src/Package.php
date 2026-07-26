<?php

namespace MODXMCP;

use MODX\Revolution\modX;

/**
 * Registers modxmcp's xPDO model package.
 *
 * The namespacePrefix argument must match the one the model was generated with,
 * otherwise xPDO resolves MODXMCP\Model\X to src/MODXMCP/Model/X.php and every
 * lookup fails with a missing-class error that points nowhere useful.
 */
final class Package
{
    private static bool $loaded = false;

    public static function load(modX $modx): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        $modx->addPackage(
            'MODXMCP\\Model',
            MODX_CORE_PATH . 'components/modxmcp/src/',
            null,
            'MODXMCP\\'
        );
    }
}
