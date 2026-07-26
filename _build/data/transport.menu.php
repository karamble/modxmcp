<?php
/**
 * Manager menu entry for the CMP.
 *
 * @var \MODX\Revolution\modX $modx
 */

$menu = $modx->newObject(\MODX\Revolution\modMenu::class);
$menu->fromArray([
    'text'        => 'modxmcp',
    'parent'      => 'components',
    'description' => 'modxmcp.menu.desc',
    // Left empty on purpose. MODX renders this field as raw markup next to the
    // title, and an element here pushes the description onto its own line with
    // a visible gap. No other extra in the Components menu sets it.
    'icon'        => '',
    'action'      => 'home',
    'namespace'   => 'modxmcp',
    'menuindex'   => 0,
    'params'      => '',
    'handler'     => '',
], '', true, true);

return $menu;
