MODxMCP.grid.Tokens = function (config) {
    config = config || {};
    Ext.applyIf(config, {
        id: 'modxmcp-grid-tokens',
        url: MODxMCP.connector(),
        baseParams: { action: 'mgr/token/getlist' },
        fields: [
            'id', 'name', 'token_prefix', 'user_id', 'username', 'scopes_display',
            'active', 'expires_at', 'ip_allowlist', 'last_used_at', 'last_used_ip', 'createdon'
        ],
        paging: true,
        pageSize: 20,
        remoteSort: true,
        autoHeight: true,
        columns: [
            { header: _('modxmcp.token.name'), dataIndex: 'name', width: 140, sortable: true,
              renderer: MODxMCP.esc },
            { header: _('modxmcp.token.prefix'), dataIndex: 'token_prefix', width: 80,
              renderer: function (v) { return '<code>' + MODxMCP.esc(v) + '</code>'; } },
            { header: _('modxmcp.token.user'), dataIndex: 'username', width: 110,
              renderer: MODxMCP.esc },
            { header: _('modxmcp.token.scopes'), dataIndex: 'scopes_display', width: 180,
              renderer: MODxMCP.esc },
            { header: _('modxmcp.token.active'), dataIndex: 'active', width: 60,
              renderer: function (v) {
                  return v == 1 ? _('yes') : '<span class="modxmcp-revoked">' + _('no') + '</span>';
              } },
            { header: _('modxmcp.token.last_used'), dataIndex: 'last_used_at', width: 130,
              sortable: true, renderer: MODxMCP.esc },
            { header: _('modxmcp.token.expires'), dataIndex: 'expires_at', width: 120,
              sortable: true, renderer: MODxMCP.esc },
            { header: _('modxmcp.token.ip_allowlist'), dataIndex: 'ip_allowlist', width: 130,
              renderer: MODxMCP.esc }
        ],
        tbar: [{
            text: '<i class="icon icon-plus"></i> ' + _('modxmcp.token.create'),
            cls: 'primary-button',
            handler: function () { MODxMCP.createToken(); }
        }]
    });
    MODxMCP.grid.Tokens.superclass.constructor.call(this, config);
};
Ext.extend(MODxMCP.grid.Tokens, MODx.grid.Grid, {
    getMenu: function () {
        var record = this.menu.record;
        if (record.active != 1) {
            return [];
        }
        return [{
            text: _('modxmcp.token.revoke'),
            handler: function () {
                MODx.msg.confirm({
                    title: _('modxmcp.token.revoke'),
                    text: _('modxmcp.token.revoke_confirm'),
                    url: MODxMCP.connector(),
                    params: { action: 'mgr/token/revoke', id: record.id },
                    listeners: { success: { fn: function () {
                        this.refresh();
                        MODxMCP.loadSettings();   // token counts in the header
                    }, scope: this } }
                });
            },
            scope: this
        }];
    }
});
Ext.reg('modxmcp-grid-tokens', MODxMCP.grid.Tokens);

/**
 * Issue a token, then reveal the plaintext once.
 *
 * Scopes are checkboxes rather than a text field: the valid values are a closed
 * set that only this extra knows, and expecting an administrator to have read
 * the docs closely enough to type "write:elements" is a poor trade for a few
 * lines of markup.
 */
MODxMCP.createToken = function () {
    var form = new Ext.FormPanel({
        labelAlign: 'top',
        border: false,
        bodyStyle: 'padding:10px',
        items: [
            { xtype: 'textfield', fieldLabel: _('modxmcp.token.name'), name: 'name',
              anchor: '100%', allowBlank: false },
            { xtype: 'modx-combo-user', fieldLabel: _('modxmcp.token.user'), name: 'user_id',
              hiddenName: 'user_id', anchor: '100%', allowBlank: false },
            { xtype: 'displayfield', value: _('modxmcp.token.user_desc'), cls: 'desc-under' },
            {
                xtype: 'fieldset',
                title: _('modxmcp.token.scopes'),
                items: [
                    { xtype: 'xcheckbox', boxLabel: _('modxmcp.scope.read'),
                      id: 'modxmcp-scope-read', checked: true },
                    { xtype: 'xcheckbox', boxLabel: _('modxmcp.scope.write_content'),
                      id: 'modxmcp-scope-write-content' },
                    { xtype: 'xcheckbox', boxLabel: _('modxmcp.scope.write_elements'),
                      id: 'modxmcp-scope-write-elements' },
                    { xtype: 'xcheckbox', boxLabel: _('modxmcp.scope.write_media'),
                      id: 'modxmcp-scope-write-media' },
                    { xtype: 'displayfield', value: _('modxmcp.scope.write_media_desc'),
                      cls: 'desc-under' },
                    { xtype: 'xcheckbox', boxLabel: _('modxmcp.scope.write_objects'),
                      id: 'modxmcp-scope-write-objects' },
                    { xtype: 'displayfield', value: _('modxmcp.scope.write_objects_desc'),
                      cls: 'desc-under' }
                ]
            },
            { xtype: 'textfield', fieldLabel: _('modxmcp.token.expires'), name: 'expires_at',
              anchor: '100%', emptyText: 'YYYY-MM-DD HH:MM:SS' },
            { xtype: 'displayfield', value: _('modxmcp.token.expires_desc'), cls: 'desc-under' },
            { xtype: 'textarea', fieldLabel: _('modxmcp.token.ip_allowlist'), name: 'ip_allowlist',
              anchor: '100%', height: 50 },
            { xtype: 'displayfield', value: _('modxmcp.token.ip_allowlist_desc'), cls: 'desc-under' }
        ]
    });

    var win = new Ext.Window({
        title: _('modxmcp.token.create'),
        width: 540,
        autoHeight: true,
        modal: true,
        items: [form],
        buttons: [
            { text: _('cancel'), handler: function () { win.close(); } },
            {
                text: _('modxmcp.token.create'),
                cls: 'primary-button',
                handler: function () {
                    if (!form.getForm().isValid()) { return; }

                    var scopes = [];
                    if (Ext.getCmp('modxmcp-scope-read').getValue()) { scopes.push('read'); }
                    if (Ext.getCmp('modxmcp-scope-write-content').getValue()) { scopes.push('write:content'); }
                    if (Ext.getCmp('modxmcp-scope-write-elements').getValue()) { scopes.push('write:elements'); }
                    if (Ext.getCmp('modxmcp-scope-write-media').getValue()) { scopes.push('write:media'); }
                    if (Ext.getCmp('modxmcp-scope-write-objects').getValue()) { scopes.push('write:objects'); }

                    form.getForm().submit({
                        url: MODxMCP.connector(),
                        params: { action: 'mgr/token/create', scopes: scopes.join(',') },
                        success: function (f, action) {
                            win.close();
                            MODxMCP.revealToken(action.result.object.token);
                            var grid = Ext.getCmp('modxmcp-grid-tokens');
                            if (grid) { grid.refresh(); }
                            MODxMCP.loadSettings();
                        },
                        failure: function (f, action) {
                            MODx.msg.alert(_('error'), action.result ? action.result.message : _('error'));
                        }
                    });
                }
            }
        ]
    });
    win.show();
};

MODxMCP.revealToken = function (token) {
    new Ext.Window({
        title: _('modxmcp.token.create'),
        width: 560,
        autoHeight: true,
        modal: true,
        closable: false,
        bodyStyle: 'padding:14px',
        html: '<p>' + _('modxmcp.token.shown_once') + '</p>'
            + '<code class="modxmcp-token-reveal">' + MODxMCP.esc(token) + '</code>',
        buttons: [{
            text: _('ok'),
            cls: 'primary-button',
            handler: function () { this.ownerCt.ownerCt.close(); }
        }]
    }).show();
};
