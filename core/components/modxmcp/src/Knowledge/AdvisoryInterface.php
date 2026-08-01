<?php

namespace MODXMCP\Knowledge;

use MODX\Revolution\modX;

/**
 * A probe for one condition.
 *
 * Implementations answer "does this site exhibit this, and what is the
 * evidence", not "is this extra installed". Returning null is the normal and
 * expected outcome: most sites do not have most conditions.
 *
 * Third-party extras contribute their own through OnMCPCollectAdvisories, the
 * same way they contribute tools through OnMCPRegisterTools.
 */
interface AdvisoryInterface
{
    /** Stable machine key, e.g. "collections.children_hidden". */
    public function id(): string;

    /** Null when the condition does not exist on this site. */
    public function detect(modX $modx): ?Advisory;
}
