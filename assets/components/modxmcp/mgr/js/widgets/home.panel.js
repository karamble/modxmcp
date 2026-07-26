MODxMCP.panel.Home = function (config) {
    config = config || {};
    Ext.applyIf(config, {
        id: 'modxmcp-panel-home',
        border: false,
        cls: 'container',
        items: [{
            html: '<h2>' + _('modxmcp') + '</h2>',
            border: false,
            cls: 'modx-page-header'
        }, {
            xtype: 'modx-tabs',
            defaults: { border: false, autoHeight: true },
            border: true,
            deferredRender: false,
            items: [{
                title: _('modxmcp.tokens'),
                layout: 'form',
                bodyStyle: 'padding:12px',
                items: [{ xtype: 'modxmcp-grid-tokens', preventRender: true }]
            }, {
                title: _('modxmcp.audit'),
                layout: 'form',
                bodyStyle: 'padding:12px',
                items: [{ xtype: 'modxmcp-grid-audit', preventRender: true }]
            }, {
                title: _('modxmcp.settings'),
                layout: 'form',
                items: [{ xtype: 'modxmcp-panel-settings', preventRender: true }]
            }]
        }]
    });
    MODxMCP.panel.Home.superclass.constructor.call(this, config);
};
Ext.extend(MODxMCP.panel.Home, MODx.Panel);
Ext.reg('modxmcp-panel-home', MODxMCP.panel.Home);
