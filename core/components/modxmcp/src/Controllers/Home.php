<?php

namespace MODXMCP\Controllers;

use MODX\Revolution\modExtraManagerController;
use MODXMCP\Runtime;

/**
 * The MCP Server manager page: tokens, audit log and settings.
 *
 * Asset order matters and is the conventional one. The namespace loader and the
 * widgets go through addJavascript; the inline block assigning MODxMCP.config
 * follows; and sections/home.js goes through addLastJavascript so it runs after
 * that config exists. Loading everything as a single addJavascript file put the
 * page's Ext.onReady ahead of the config assignment, so MODxMCP.config was
 * undefined at render time and the page came up empty.
 */
class Home extends modExtraManagerController
{
    public function getLanguageTopics()
    {
        return ['modxmcp:default'];
    }

    public function checkPermissions()
    {
        // Issuing a token grants API access acting as a MODX user, so this page
        // is gated on the same permission as system settings rather than on
        // mere Manager access.
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

        $this->addJavascript($assets . 'js/modxmcp.js');
        $this->addJavascript($assets . 'js/widgets/tokens.grid.js');
        $this->addJavascript($assets . 'js/widgets/audit.grid.js');
        $this->addJavascript($assets . 'js/widgets/settings.panel.js');
        $this->addJavascript($assets . 'js/widgets/home.panel.js');

        // Hex-escaping tags and quotes so nothing in the encoded payload can
        // close the inline <script>.
        $config = json_encode(
            $this->config(),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        $this->addHtml('<script type="text/javascript">
            Ext.onReady(function() {
                MODxMCP.config = ' . $config . ';
            });
        </script>');

        // Must be last: it renders the page and needs the config above.
        $this->addLastJavascript($assets . 'js/sections/home.js');
    }

    public function getTemplateFile()
    {
        return $this->modx->getOption('core_path', null, MODX_CORE_PATH)
            . 'components/modxmcp/templates/home.tpl';
    }

    public function process(array $scriptProperties = [])
    {
        return '';
    }

    /**
     * The endpoint URL and counts are fetched by the page from
     * mgr/settings/get, not embedded here: settings are editable on this page,
     * so anything baked in at render time would go stale the moment something
     * was saved.
     *
     * @return array<string,mixed>
     */
    private function config(): array
    {
        return [
            'connector' => $this->modx->getOption('assets_url', null, MODX_ASSETS_URL)
                . 'components/modxmcp/connector.php',
        ];
    }
}
