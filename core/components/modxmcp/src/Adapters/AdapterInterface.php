<?php

namespace MODXMCP\Adapters;

use MODX\Revolution\modX;
use MODXMCP\Registry\ToolRegistry;

/**
 * Extra-specific tools, registered only when that extra is installed.
 *
 * Schema discovery reaches any extra's data, but it can only ever describe
 * structure. The rules that actually break sites live in extras' event handlers
 * and config blobs: that a Collections child needs show_in_tree=0, that an alias
 * change needs a redirect row SeoSuite will not create on its own, that a MIGX
 * template variable's JSON has a shape defined nowhere in the database schema.
 * Adapters are where that knowledge is encoded, because nothing can infer it.
 */
interface AdapterInterface
{
    /** Namespace name of the extra this adapts, e.g. "seosuite". */
    public function extra(): string;

    /**
     * Whether this adapter's tools should be offered on this site.
     *
     * Registering tools for an absent extra would advertise capabilities that
     * fail on use, which is worse than not offering them.
     */
    public function supports(modX $modx): bool;

    public function register(ToolRegistry $registry): void;
}
