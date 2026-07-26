<?php
/**
 * Loader stub.
 *
 * MODX resolves a manager controller by class name from this directory; the
 * implementation lives in src/Controllers/ under the PSR-4 namespace.
 *
 * The autoloader has to be registered HERE, before the class declaration.
 * Everywhere else modxmcp is entered through Runtime::boot(), but the manager
 * reaches this file directly and the parent class cannot be resolved without
 * the autoloader already in place. Registering it inside the controller would
 * be circular: the method could not run until the class it belongs to had
 * loaded.
 */

require_once MODX_CORE_PATH . 'components/modxmcp/src/Runtime.php';

\MODXMCP\Runtime::boot();

class ModxmcpHomeManagerController extends \MODXMCP\Controllers\Home
{
}
