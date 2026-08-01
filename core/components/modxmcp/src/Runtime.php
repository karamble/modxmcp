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

        // Categories, which elements are filed under. Without these a caller can
        // read a category id and never learn its name, so "put this in the Blog
        // category" has no path to an answer.
        $registry->register(new Tools\CategoryListTool());
        $registry->register(new Tools\CategorySaveTool());

        // Cross-cutting search. No MODX processor equivalent, because the
        // Manager has no screen for it, and answering "who calls this element"
        // is the question that has to be settled before any rename.
        $registry->register(new Tools\SearchTool());

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

        // Read-only visibility of what is installed and what is behind. Reads
        // MODX's own update cache; deliberately cannot install or update.
        $registry->register(new Tools\UpdatesTool());

        self::registerAdapters($modx, $registry);
        self::invokeRegistrationEvent($modx, $registry);

        return $registry;
    }

    /**
     * First-party adapters, each registering only if its extra is installed.
     *
     * These encode rules that discovery can never surface, because they live in
     * extras' event handlers and config blobs rather than in any schema.
     */
    private static function registerAdapters(modX $modx, ToolRegistry $registry): void
    {
        $adapters = [
            new Adapters\CollectionsAdapter(),
            new Adapters\SeoSuiteAdapter(),
            new Adapters\MigxAdapter(),
        ];

        foreach ($adapters as $adapter) {
            try {
                if ($adapter->supports($modx)) {
                    $adapter->register($registry);
                }
            } catch (\Throwable $e) {
                // One adapter misbehaving must not cost the caller every tool.
                $modx->log(modX::LOG_LEVEL_ERROR,
                    'modxmcp: adapter ' . $adapter->extra() . ' failed to register: ' . $e->getMessage());
            }
        }
    }

    /**
     * Let third-party extras contribute tools.
     *
     * The registry is passed as an object, so a plugin registers by calling
     * $scriptProperties['registry']->register(new MyTool()) with a class
     * implementing MODXMCP\Registry\ToolInterface.
     *
     * Wrapped because a plugin here is arbitrary third-party code running on
     * every MCP request: a fatal in someone's plugin must degrade to "their
     * tools are missing", never to a dead endpoint.
     */
    private static function invokeRegistrationEvent(modX $modx, ToolRegistry $registry): void
    {
        try {
            $modx->invokeEvent('OnMCPRegisterTools', [
                'registry' => $registry,
                'modx'     => $modx,
            ]);
        } catch (\Throwable $e) {
            $modx->log(modX::LOG_LEVEL_ERROR,
                'modxmcp: a plugin on OnMCPRegisterTools threw: ' . $e->getMessage());
        }
    }
}
