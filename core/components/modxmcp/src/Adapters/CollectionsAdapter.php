<?php

namespace MODXMCP\Adapters;

use MODXMCP\Registry\ToolRegistry;
use MODXMCP\Tools\CollectionsContainersTool;

final class CollectionsAdapter extends AbstractAdapter
{
    public function extra(): string
    {
        return 'collections';
    }

    public function register(ToolRegistry $registry): void
    {
        $registry->register(new CollectionsContainersTool());
    }
}
