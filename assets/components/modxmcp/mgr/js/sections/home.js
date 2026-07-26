/**
 * Page entry point.
 *
 * The controller loads this with addLastJavascript, so it runs after the inline
 * block that assigns MODxMCP.config. That ordering is the whole reason the
 * section file exists as a separate file rather than being folded in with the
 * widgets.
 */
Ext.onReady(function () {
    MODx.load({ xtype: 'modxmcp-page-home' });
});

MODxMCP.page.Home = function (config) {
    config = config || {};
    Ext.applyIf(config, {
        components: [{
            xtype: 'modxmcp-panel-home',
            renderTo: 'modxmcp-panel-home-div'
        }]
    });
    MODxMCP.page.Home.superclass.constructor.call(this, config);

    // Populates the settings form and the endpoint/counts header. Deferred so
    // the fields exist by the time the response is applied to them.
    MODxMCP.loadSettings.defer(50);
};
Ext.extend(MODxMCP.page.Home, MODx.Component);
Ext.reg('modxmcp-page-home', MODxMCP.page.Home);
