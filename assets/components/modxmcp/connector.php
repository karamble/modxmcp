<?php
/**
 * Manager connector for the modxmcp CMP.
 *
 * Used ONLY by the manager page's grids. The MCP endpoint itself does not go
 * through here: it is a snippet in a resource, and needs no web-accessible PHP.
 *
 * On installs that deny PHP execution under assets/, this file is unreachable
 * and the manager page's grids will not load. That is a deliberate trade rather
 * than an oversight: the endpoint keeps working, and tokens can be managed from
 * the CLI. To use the manager page on such a site, carve this one directory out
 * of the site's hardening.
 */

require_once dirname(__DIR__, 3) . '/config.core.php';
require_once MODX_CORE_PATH . 'config/' . MODX_CONFIG_KEY . '.inc.php';

// Tells connectors/index.php that a wrapper is driving the request, so it
// bootstraps and authenticates but leaves dispatch to us.
define('MODX_CONNECTOR_INCLUDED', true);
require_once MODX_CONNECTORS_PATH . 'index.php';

require_once MODX_CORE_PATH . 'components/modxmcp/src/Runtime.php';
\MODXMCP\Runtime::boot();
\MODXMCP\Package::load($modx);

$modx->lexicon->load('modxmcp:default');

$modx->request->handleRequest([
    'processors_path' => MODX_CORE_PATH . 'components/modxmcp/processors/',
    'location'        => '',
]);
