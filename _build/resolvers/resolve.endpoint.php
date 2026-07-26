<?php
/**
 * Register the OnMCPRegisterTools event and create the endpoint resource.
 *
 * The endpoint has to be a resource: it is how modxmcp avoids shipping any
 * web-accessible PHP, which is what lets it work on installs that deny PHP
 * execution under assets/. Shipping a resource from a package is unusual, so it
 * is done carefully: created only when absent, never overwritten on upgrade,
 * unpublished-safe, and given a random alias so the endpoint is not sitting at
 * a guessable URL on every site that installs this.
 *
 * @var \xPDO\Transport\xPDOObjectVehicle $object
 * @var array $options
 */

use MODX\Revolution\modEvent;
use MODX\Revolution\modResource;
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

// --- the extension point third-party extras bind to -------------------------

$event = $modx->getObject(modEvent::class, ['name' => 'OnMCPRegisterTools']) ?: $modx->newObject(modEvent::class);
$event->fromArray([
    'name'      => 'OnMCPRegisterTools',
    'service'   => 6,
    'groupname' => 'modxmcp',
], '', true, true);
$event->save();

// --- the endpoint -----------------------------------------------------------

// Any resource already calling the snippet is the endpoint; do not make another.
$existing = $modx->getObject(modResource::class, ['content:LIKE' => '%[[!modxmcp%', 'deleted' => 0]);
if ($existing) {
    $modx->log(modX::LOG_LEVEL_INFO,
        'modxmcp: endpoint resource already present (id ' . $existing->get('id') . '), left unchanged.');
    return true;
}

// Unguessable by default. The endpoint requires a token regardless, but there
// is no reason to publish a known path for scanners to find on every install.
try {
    $suffix = bin2hex(random_bytes(6));
} catch (\Throwable $e) {
    $suffix = substr(md5(uniqid('modxmcp', true)), 0, 12);
}

$resource = $modx->newObject(modResource::class);
$resource->fromArray([
    'pagetitle'    => 'MCP endpoint',
    'description'  => 'modxmcp API endpoint. Do not link to this, add it to a menu, or change '
        . 'its content. Rename the alias if you want a different endpoint URL.',
    'alias'        => 'mcp-' . $suffix,
    'parent'       => 0,
    'template'     => 0,      // blank: nothing may wrap the JSON
    'published'    => 1,
    'hidemenu'     => 1,
    'searchable'   => 0,      // must not appear in site search
    'cacheable'    => 0,      // a cached MCP response would be nonsense
    'richtext'     => 0,
    'context_key'  => 'web',
    'content_type' => 1,
    'content'      => '[[!modxmcp]]',
], '', true, true);

if (!$resource->save()) {
    $modx->log(modX::LOG_LEVEL_ERROR,
        'modxmcp: could not create the endpoint resource. Create one manually: a resource with '
        . 'template 0, uncacheable, containing [[!modxmcp]].');
    return true;   // the rest of the package is still usable
}

$modx->log(modX::LOG_LEVEL_INFO, 'modxmcp: endpoint created at ' . $resource->get('uri'));

return true;
