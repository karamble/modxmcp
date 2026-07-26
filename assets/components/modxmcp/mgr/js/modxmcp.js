/**
 * modxmcp manager loader.
 *
 * Follows the layout every MODX extra uses (FormIt, SeoSuite, modAI): a
 * namespace object here, widgets in widgets/, and the page assembled in
 * sections/home.js which the controller loads with addLastJavascript so it runs
 * after the inline config block. Loading everything as one file with
 * addJavascript put this file's Ext.onReady ahead of the config assignment, so
 * MODxMCP.config was undefined when the page tried to render and nothing
 * appeared at all.
 */
var MODxMCP = function (config) {
    config = config || {};
    MODxMCP.superclass.constructor.call(this, config);
};

Ext.extend(MODxMCP, Ext.Component, {
    page:   {},
    window: {},
    grid:   {},
    panel:  {},
    combo:  {},
    config: {}
});

Ext.reg('modxmcp', MODxMCP);

MODxMCP = new MODxMCP();

/**
 * ExtJS 3 writes a column value straight into innerHTML unless the column has a
 * renderer, and several of these values arrive from unauthenticated callers, so
 * anything caller-influenced goes through here.
 */
MODxMCP.esc = function (v) {
    return v === null || v === undefined ? '' : Ext.util.Format.htmlEncode(v);
};

MODxMCP.connector = function () {
    return MODxMCP.config.connector;
};
