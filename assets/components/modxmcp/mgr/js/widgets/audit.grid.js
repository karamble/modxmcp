MODxMCP.grid.Audit = function (config) {
    config = config || {};
    Ext.applyIf(config, {
        id: 'modxmcp-grid-audit',
        url: MODxMCP.connector(),
        baseParams: { action: 'mgr/audit/getlist' },
        fields: [
            'id', 'createdon', 'rpc_method', 'tool', 'result', 'success',
            'error_code', 'error_message', 'ip', 'username', 'duration_ms', 'has_arguments'
        ],
        paging: true,
        pageSize: 25,
        remoteSort: true,
        autoHeight: true,
        columns: [
            { header: _('modxmcp.audit.when'), dataIndex: 'createdon', width: 140,
              sortable: true, renderer: MODxMCP.esc },
            // rpc_method and tool come straight from the request body, and a row
            // is written even when authentication fails, so these are reachable
            // by an unauthenticated caller. They must never render raw.
            { header: _('modxmcp.audit.method'), dataIndex: 'rpc_method', width: 120,
              renderer: MODxMCP.esc },
            { header: _('modxmcp.audit.tool'), dataIndex: 'tool', width: 150,
              renderer: MODxMCP.esc },
            { header: _('modxmcp.audit.result'), dataIndex: 'result', width: 70,
              renderer: function (v, m, rec) {
                  return rec.data.success == 1
                      ? MODxMCP.esc(v)
                      : '<span class="modxmcp-failed">' + MODxMCP.esc(v) + '</span>';
              } },
            { header: _('modxmcp.audit.error'), dataIndex: 'error_message', width: 240,
              renderer: function (v, m, rec) {
                  if (!v) { return ''; }
                  m.attr = 'title="' + MODxMCP.esc(v) + '"';
                  return MODxMCP.esc(rec.data.error_code + ': ' + v);
              } },
            { header: _('modxmcp.audit.ip'), dataIndex: 'ip', width: 110, renderer: MODxMCP.esc },
            { header: _('modxmcp.audit.duration'), dataIndex: 'duration_ms', width: 60 }
        ],
        tbar: [{
            xtype: 'xcheckbox',
            boxLabel: _('modxmcp.audit.failures_only'),
            listeners: {
                check: function (cb, checked) {
                    var g = Ext.getCmp('modxmcp-grid-audit');
                    g.getStore().baseParams.failures_only = checked ? 1 : 0;
                    g.getBottomToolbar().changePage(1);
                }
            }
        }, {
            text: _('modxmcp.audit.clear'),
            handler: function () {
                MODx.msg.confirm({
                    title: _('modxmcp.audit.clear'),
                    text: _('modxmcp.audit.clear_confirm'),
                    url: MODxMCP.connector(),
                    params: { action: 'mgr/audit/clear' },
                    listeners: { success: { fn: function () {
                        MODx.msg.status({ title: _('success'), message: _('modxmcp.audit.cleared'), delay: 2 });
                        var g = Ext.getCmp('modxmcp-grid-audit');
                        if (g) { g.getBottomToolbar().changePage(1); }
                        MODxMCP.loadSettings();
                    } } }
                });
            }
        }, '->', {
            xtype: 'textfield',
            emptyText: _('search') + '...',
            listeners: {
                change: function (tf, v) {
                    var g = Ext.getCmp('modxmcp-grid-audit');
                    g.getStore().baseParams.query = v;
                    g.getBottomToolbar().changePage(1);
                }
            }
        }]
    });
    MODxMCP.grid.Audit.superclass.constructor.call(this, config);
};
Ext.extend(MODxMCP.grid.Audit, MODx.grid.Grid, {
    getMenu: function () { return []; }
});
Ext.reg('modxmcp-grid-audit', MODxMCP.grid.Audit);
