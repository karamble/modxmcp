<?php
/**
 * Local build paths. Copy to build.config.php and edit MODX_BASE_PATH.
 *
 * build.config.php is gitignored: it points at whichever MODX install is used
 * to run the build, which is a property of the machine, not of the package.
 *
 * All of these must be defined, not just the paths. MODX's config.inc.php
 * guards them as a group, so defining MODX_BASE_PATH alone skips the block that
 * would otherwise define MODX_BASE_URL, and the build then dies on an undefined
 * constant deep inside modX::loadConfig().
 */

define('MODX_BASE_PATH', '/path/to/modx/');
define('MODX_CORE_PATH', MODX_BASE_PATH . 'core/');
define('MODX_MANAGER_PATH', MODX_BASE_PATH . 'manager/');
define('MODX_CONNECTORS_PATH', MODX_BASE_PATH . 'connectors/');
define('MODX_ASSETS_PATH', MODX_BASE_PATH . 'assets/');

define('MODX_BASE_URL', '/');
define('MODX_MANAGER_URL', MODX_BASE_URL . 'manager/');
define('MODX_CONNECTORS_URL', MODX_BASE_URL . 'connectors/');
define('MODX_ASSETS_URL', MODX_BASE_URL . 'assets/');

define('MODX_CONFIG_KEY', 'config');
