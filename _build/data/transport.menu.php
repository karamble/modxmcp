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
    'icon'        => '<i class="icon icon-plug"></i>',
    'action'      => 'home',
    'namespace'   => 'modxmcp',
    'menuindex'   => 0,
    'params'      => '',
    'handler'     => '',
], '', true, true);

return $menu;
