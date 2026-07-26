<?php

namespace MODXMCP\Adapters;

use MODX\Revolution\modNamespace;
use MODX\Revolution\modX;

/**
 * Presence detection shared by the first-party adapters.
 */
abstract class AbstractAdapter implements AdapterInterface
{
    /**
     * An extra is present if it registered a namespace. That is what MODX's own
     * package installer creates, so it is a better signal than looking for
     * files, which survive an uninstall.
     */
    public function supports(modX $modx): bool
    {
        return (bool) $modx->getObject(modNamespace::class, ['name' => $this->extra()]);
    }
}
