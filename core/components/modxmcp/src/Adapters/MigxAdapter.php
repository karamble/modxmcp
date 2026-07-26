<?php

namespace MODXMCP\Adapters;

use MODXMCP\Registry\ToolRegistry;
use MODXMCP\Tools\MigxDescribeTool;

final class MigxAdapter extends AbstractAdapter
{
    public function extra(): string
    {
        return 'migx';
    }

    public function register(ToolRegistry $registry): void
    {
        $registry->register(new MigxDescribeTool());
    }
}
