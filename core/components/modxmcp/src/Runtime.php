<?php

namespace MODXMCP;

use MODX\Revolution\modX;
use MODXMCP\Audit\AuditLogger;
use MODXMCP\Auth\Authenticator;
use MODXMCP\Auth\TokenService;
use MODXMCP\Protocol\HttpTransport;
use MODXMCP\Protocol\V20260728;
use MODXMCP\Registry\ToolRegistry;
use MODXMCP\Tools\SiteInfoTool;

/**
 * Builds a configured Server.
 *
 * Both entry points come through here so the two routes cannot drift apart in
 * what they register or how they are configured.
 */
final class Runtime
{
    /**
     * Registers the autoloader. Safe to call repeatedly.
     */
    public static function boot(): void
    {
        $src = MODX_CORE_PATH . 'components/modxmcp/src/';
        require_once $src . 'Autoloader.php';
        Autoloader::register($src);
    }

    public static function server(modX $modx): Server
    {
        Package::load($modx);

        return new Server(
            $modx,
            new HttpTransport(),
            new V20260728(),
            new TokenService(),
            new Authenticator(),
            self::registry($modx),
            new AuditLogger((bool) $modx->getOption('modxmcp.log_arguments', null, false))
        );
    }

    private static function registry(modX $modx): ToolRegistry
    {
        $registry = new ToolRegistry();

        // Orientation
        $registry->register(new SiteInfoTool());

        // Resources. Every write goes through a MODX processor.
        $registry->register(new Tools\ResourceListTool());
        $registry->register(new Tools\ResourceGetTool());
        $registry->register(new Tools\ResourceCreateTool());
        $registry->register(new Tools\ResourceUpdateTool());
        $registry->register(new Tools\ResourceDeleteTool());

        // Elements, all five types behind one discriminated set.
        $registry->register(new Tools\ElementListTool());
        $registry->register(new Tools\ElementGetTool());
        $registry->register(new Tools\ElementSaveTool());
        $registry->register(new Tools\ElementDeleteTool());

        // Schema discovery. Metadata only, so these need no allowlist: a caller
        // that cannot see what exists cannot tell the user what to enable.
        $registry->register(new Tools\SchemaListTool());
        $registry->register(new Tools\SchemaDescribeTool());

        // Generic object access over any discovered class. Gated per class by
        // ClassGuard, which for arbitrary classes is the only access control
        // there is: xPDO has no permission model and these bypass processors.
        $registry->register(new Tools\ObjectListTool());
        $registry->register(new Tools\ObjectSaveTool());
        $registry->register(new Tools\ObjectDeleteTool());

        // Maintenance
        $registry->register(new Tools\CacheRefreshTool());

        // M5 opens this to third-party extras via OnMCPRegisterTools.

        return $registry;
    }
}
