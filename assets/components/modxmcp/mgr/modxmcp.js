/**
 * modxmcp manager page.
 *
 * Two grids: issued tokens, and the audit log. MODX 3's manager is still
 * ExtJS 3, so this follows the MODx.grid.Grid conventions rather than anything
 * more modern.
 */
var MODxMCP = MODxMCP || {};
MODxMCP.grid = MODxMCP.grid || {};

MODxMCP.esc = function (v) {
    return v === null || v === undefined ? '' : Ext.util.Format.htmlEncode(v);
};

MODxMCP.connector = function () {
    return MODxMCP.config.connector;
};

/* ------------------------------------------------------------------ tokens */

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
              renderer: function (v) { return '<code>' + Ext.util.Format.htmlEncode(v) + '</code>'; } },
            { header: _('modxmcp.token.user'), dataIndex: 'username', width: 110, renderer: MODxMCP.esc },
            { header: _('modxmcp.token.scopes'), dataIndex: 'scopes_display', width: 180,
              renderer: MODxMCP.esc },
            { header: _('modxmcp.token.active'), dataIndex: 'active', width: 60,
              renderer: function (v) {
                  return v == 1 ? _('yes') : '<span class="modxmcp-revoked">' + _('no') + '</span>';
              } },
            { header: _('modxmcp.token.last_used'), dataIndex: 'last_used_at', width: 130, sortable: true },
            { header: _('modxmcp.token.expires'), dataIndex: 'expires_at', width: 120, sortable: true },
            { header: _('modxmcp.token.ip_allowlist'), dataIndex: 'ip_allowlist', width: 130,
              renderer: MODxMCP.esc }
        ],
        tbar: [{
            text: '<i class="icon icon-plus"></i> ' + _('modxmcp.token.create'),
            cls: 'primary-button',
            handler: function () { MODxMCP.createToken(this); },
            scope: this
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
                    listeners: { success: { fn: function () { this.refresh(); }, scope: this } }
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
 * The response is the only time the token exists in readable form, so the
 * dialog blocks on an explicit acknowledgement rather than auto-closing.
 */
MODxMCP.createToken = function (grid) {
    var form = new Ext.FormPanel({
        labelAlign: 'top',
        border: false,
        bodyStyle: 'padding:10px',
        items: [
            { xtype: 'textfield', fieldLabel: _('modxmcp.token.name'), name: 'name', anchor: '100%', allowBlank: false },
            { xtype: 'modx-combo-user', fieldLabel: _('modxmcp.token.user'), name: 'user_id',
              hiddenName: 'user_id', anchor: '100%', allowBlank: false },
            { xtype: 'displayfield', value: _('modxmcp.token.user_desc'), cls: 'desc-under' },
            { xtype: 'textfield', fieldLabel: _('modxmcp.token.scopes'), name: 'scopes',
              anchor: '100%', value: 'read' },
            { xtype: 'displayfield', value: _('modxmcp.token.scopes_desc'), cls: 'desc-under' },
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
        width: 520,
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
                    form.getForm().submit({
                        url: MODxMCP.connector(),
                        params: { action: 'mgr/token/create' },
                        success: function (f, action) {
                            win.close();
                            MODxMCP.revealToken(action.result.object.token);
                            if (grid && grid.refresh) { grid.refresh(); }
                            else { Ext.getCmp('modxmcp-grid-tokens').refresh(); }
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
            + '<code class="modxmcp-token-reveal">' + Ext.util.Format.htmlEncode(token) + '</code>',
        buttons: [{
            text: _('ok'),
            cls: 'primary-button',
            handler: function () { this.ownerCt.ownerCt.close(); }
        }]
    }).show();
};

/* ------------------------------------------------------------------- audit */

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
            { header: _('modxmcp.audit.when'), dataIndex: 'createdon', width: 140, sortable: true },
            { header: _('modxmcp.audit.method'), dataIndex: 'rpc_method', width: 120,
              renderer: MODxMCP.esc },
            { header: _('modxmcp.audit.tool'), dataIndex: 'tool', width: 150,
              renderer: MODxMCP.esc },
            { header: _('modxmcp.audit.result'), dataIndex: 'result', width: 70,
              renderer: function (v, m, rec) {
                  return rec.data.success == 1 ? v : '<span style="color:#b3261e">' + v + '</span>';
              } },
            { header: _('modxmcp.audit.error'), dataIndex: 'error_message', width: 240,
              renderer: function (v, m, rec) {
                  if (!v) { return ''; }
                  m.attr = 'title="' + Ext.util.Format.htmlEncode(v) + '"';
                  return Ext.util.Format.htmlEncode(rec.data.error_code + ': ' + v);
              } },
            { header: _('modxmcp.audit.ip'), dataIndex: 'ip', width: 110, renderer: MODxMCP.esc },
            { header: _('modxmcp.audit.duration'), dataIndex: 'duration_ms', width: 60 }
        ],
        tbar: [{
            xtype: 'checkbox',
            boxLabel: _('modxmcp.audit.failed'),
            listeners: {
                check: function (cb, checked) {
                    var g = Ext.getCmp('modxmcp-grid-audit');
                    g.getStore().baseParams.failures_only = checked ? 1 : 0;
                    g.getBottomToolbar().changePage(1);
                }
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


/* ---------------------------------------------------------------- settings */

/**
 * Everything configurable about this extra, on the extra's own page.
 *
 * These are ordinary system settings underneath, but an administrator should
 * never have to leave here to find them: sending someone to System Settings to
 * switch the endpoint on is the kind of scavenger hunt that makes an extra feel
 * half-finished.
 */
MODxMCP.SettingsPanel = function (config) {
    config = config || {};

    var desc = function (key) {
        return { xtype: 'displayfield', value: _(key), cls: 'desc-under' };
    };

    Ext.applyIf(config, {
        id: 'modxmcp-settings-panel',
        cls: 'form-with-labels',
        bodyStyle: 'padding:14px',
        border: false,
        labelAlign: 'top',
        items: [{
            xtype: 'fieldset',
            title: _('modxmcp.settings.general'),
            items: [
                { xtype: 'xcheckbox', boxLabel: _('modxmcp.set.enabled'), name: 'enabled',
                  id: 'modxmcp-set-enabled', inputValue: 1 },
                desc('modxmcp.set.enabled_desc'),
                { xtype: 'xcheckbox', boxLabel: _('modxmcp.set.log_arguments'), name: 'log_arguments',
                  id: 'modxmcp-set-log_arguments', inputValue: 1 },
                desc('modxmcp.set.log_arguments_desc'),
                { xtype: 'numberfield', fieldLabel: _('modxmcp.set.audit_retention_days'),
                  name: 'audit_retention_days', id: 'modxmcp-set-audit_retention_days', width: 120, allowNegative: false },
                desc('modxmcp.set.audit_retention_days_desc'),
                { xtype: 'numberfield', fieldLabel: _('modxmcp.set.discovery_cache_seconds'),
                  name: 'discovery_cache_seconds', id: 'modxmcp-set-discovery_cache_seconds', width: 120, allowNegative: false },
                desc('modxmcp.set.discovery_cache_seconds_desc'),
                { xtype: 'textfield', fieldLabel: _('modxmcp.set.trusted_proxy_header'),
                  name: 'trusted_proxy_header', id: 'modxmcp-set-trusted_proxy_header', anchor: '60%' },
                desc('modxmcp.set.trusted_proxy_header_desc')
            ]
        }, {
            xtype: 'fieldset',
            title: _('modxmcp.settings.generic_access'),
            items: [
                { xtype: 'textarea', fieldLabel: _('modxmcp.set.read_class_allowlist'),
                  name: 'read_class_allowlist', id: 'modxmcp-set-read_class_allowlist', anchor: '100%', height: 60 },
                desc('modxmcp.set.read_class_allowlist_desc'),
                { xtype: 'textarea', fieldLabel: _('modxmcp.set.write_class_allowlist'),
                  name: 'write_class_allowlist', id: 'modxmcp-set-write_class_allowlist', anchor: '100%', height: 60 },
                desc('modxmcp.set.write_class_allowlist_desc')
            ]
        }],
        buttons: [{
            text: _('modxmcp.settings.save'),
            cls: 'primary-button',
            handler: function () { MODxMCP.saveSettings(); }
        }]
    });
    MODxMCP.SettingsPanel.superclass.constructor.call(this, config);
};
Ext.extend(MODxMCP.SettingsPanel, Ext.FormPanel);
Ext.reg('modxmcp-settings-panel', MODxMCP.SettingsPanel);

MODxMCP.SETTING_KEYS = [
    'enabled', 'log_arguments', 'audit_retention_days', 'discovery_cache_seconds',
    'trusted_proxy_header', 'read_class_allowlist', 'write_class_allowlist'
];

MODxMCP.loadSettings = function () {
    MODx.Ajax.request({
        url: MODxMCP.connector(),
        params: { action: 'mgr/settings/get' },
        listeners: { success: { fn: function (r) {
            var values = (r.object && r.object.settings) || {};
            Ext.each(MODxMCP.SETTING_KEYS, function (key) {
                var field = Ext.getCmp('modxmcp-set-' + key);
                if (!field) { return; }
                if (field.getXType() === 'xcheckbox') {
                    field.setValue(values[key] == 1);
                } else {
                    field.setValue(values[key]);
                }
            });
            MODxMCP.renderStatus(r.object);
        } } }
    });
};

MODxMCP.saveSettings = function () {
    var params = { action: 'mgr/settings/update' };
    Ext.each(MODxMCP.SETTING_KEYS, function (key) {
        var field = Ext.getCmp('modxmcp-set-' + key);
        if (!field) { return; }
        params[key] = field.getXType() === 'xcheckbox' ? (field.getValue() ? 1 : 0) : field.getValue();
    });

    MODx.Ajax.request({
        url: MODxMCP.connector(),
        params: params,
        listeners: { success: { fn: function () {
            MODx.msg.status({ title: _('success'), message: _('modxmcp.settings.saved'), delay: 2 });
            // Re-read rather than assume: the server normalises list separators
            // and clamps numbers, so what was typed is not always what is stored.
            MODxMCP.loadSettings();
        } } }
    });
};

/** Endpoint URL and live counts, kept in step with whatever was just saved. */
MODxMCP.renderStatus = function (data) {
    var target = Ext.get('modxmcp-status');
    if (!target || !data) { return; }

    var enabled = Ext.getCmp('modxmcp-set-enabled') && Ext.getCmp('modxmcp-set-enabled').getValue();
    var stats = data.stats || {};
    var html = '';

    if (data.endpoint) {
        html += '<div class="modxmcp-banner ' + (enabled ? 'modxmcp-banner-ok' : 'modxmcp-banner-warn') + '">'
             + MODxMCP.esc(_('modxmcp.endpoint')) + ': <code>' + MODxMCP.esc(data.endpoint) + '</code>'
             + (enabled ? '' : ' &mdash; ' + MODxMCP.esc(_('modxmcp.disabled_warning')))
             + '</div>';
    }

    html += '<div class="modxmcp-stats">'
         + MODxMCP.esc(_('modxmcp.stats.tokens')) + ': <strong>' + (stats.tokens_active || 0) + '</strong>'
         + ' / ' + (stats.tokens_total || 0)
         + ' &middot; ' + MODxMCP.esc(_('modxmcp.stats.audit')) + ': <strong>' + (stats.audit_total || 0) + '</strong>'
         + ' (' + (stats.audit_failed || 0) + ' ' + MODxMCP.esc(_('modxmcp.stats.failed')) + ')'
         + '</div>';

    target.dom.innerHTML = html;
};

/* -------------------------------------------------------------------- page */

Ext.onReady(function () {
    var target = Ext.get('modxmcp-panel-home');
    if (!target) { return; }

    MODxMCP.loadSettings();

    new Ext.Panel({
        renderTo: 'modxmcp-panel-home',
        border: false,
        items: [{
            xtype: 'modx-tabs',
            deferredRender: false,
            border: true,
            items: [
                { title: _('modxmcp.tokens'), layout: 'form', bodyStyle: 'padding:12px',
                  items: [{ xtype: 'modxmcp-grid-tokens', preventRender: true }] },
                { title: _('modxmcp.audit'), layout: 'form', bodyStyle: 'padding:12px',
                  items: [{ xtype: 'modxmcp-grid-audit', preventRender: true }] },
                { title: _('modxmcp.settings'), layout: 'form',
                  items: [{ xtype: 'modxmcp-settings-panel', preventRender: true }] }
            ]
        }]
    });
});
