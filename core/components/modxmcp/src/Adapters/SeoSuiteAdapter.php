<?php

namespace MODXMCP\Adapters;

use MODXMCP\Registry\ToolRegistry;
use MODXMCP\Tools\SeoRedirectTool;

final class SeoSuiteAdapter extends AbstractAdapter
{
    public function extra(): string
    {
        return 'seosuite';
    }

    public function register(ToolRegistry $registry): void
    {
        $registry->register(new SeoRedirectTool());
    }
}
