<?php

namespace MODXMCP\Controllers;

use MODX\Revolution\modExtraManagerController;
use MODX\Revolution\modResource;
use MODXMCP\Runtime;

/**
 * The MCP Server manager page: token management and the audit log.
 */
class Home extends modExtraManagerController
{
    public function getLanguageTopics()
    {
        return ['modxmcp:default'];
    }

    public function checkPermissions()
    {
        // Issuing a token grants API access that acts as a MODX user, so this
        // page is gated on the same permission as system settings rather than
        // on mere Manager access.
        return $this->modx->hasPermission('settings');
    }

    public function getPageTitle()
    {
        return $this->modx->lexicon('modxmcp');
    }

    public function loadCustomCssJs()
    {
        Runtime::boot();

        $assets = $this->modx->getOption('assets_url', null, MODX_ASSETS_URL) . 'components/modxmcp/mgr/';

        $this->addCss($assets . 'modxmcp.css');
        $this->addJavascript($assets . 'modxmcp.js');

        // Hex-escaping tags and quotes so nothing in the encoded payload can
        // close the inline <script>. Both values are privileged, so this is
        // hardening rather than a live hole, but a literal </script> in a site
        // URL should not be able to break the page open.
        $config = json_encode(
            $this->config(),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        $this->addHtml('<script>Ext.onReady(function(){ MODxMCP.config = ' . $config . '; });</script>');
    }

    public function getTemplateFile()
    {
        return $this->modx->getOption('core_path', null, MODX_CORE_PATH)
            . 'components/modxmcp/templates/home.tpl';
    }

    public function process(array $scriptProperties = [])
    {
        $config = $this->config();
        $this->setPlaceholder('enabled', $config['enabled']);
        $this->setPlaceholder('endpoint', $config['endpoint']);
        return '';
    }

    /**
     * Values the page needs to describe the current install.
     *
     * @return array<string,mixed>
     */
    private function config(): array
    {
        return [
            'connector' => $this->modx->getOption('assets_url', null, MODX_ASSETS_URL)
                . 'components/modxmcp/connector.php',
            'enabled'   => (bool) $this->modx->getOption('modxmcp.enabled', null, false),
            'endpoint'  => $this->endpointUrl(),
        ];
    }

    /**
     * Resolve the endpoint from the resource that actually hosts the snippet,
     * rather than assuming an alias. The alias is the site owner's to change,
     * and a stale URL printed here would be worse than none.
     */
    private function endpointUrl(): string
    {
        $c = $this->modx->newQuery(modResource::class);
        $c->where(['content:LIKE' => '%[[!modxmcp%', 'published' => 1, 'deleted' => 0]);
        $c->limit(1);

        /** @var modResource|null $resource */
        $resource = $this->modx->getObject(modResource::class, $c);
        if (!$resource) {
            return '';
        }

        return rtrim((string) $this->modx->getOption('site_url'), '/') . '/' . ltrim((string) $resource->get('uri'), '/');
    }
}
