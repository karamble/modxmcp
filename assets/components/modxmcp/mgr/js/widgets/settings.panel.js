/**
 * Everything configurable about this extra, on the extra's own page.
 *
 * These are ordinary system settings underneath, but an administrator should
 * never have to leave here to find them: being sent to System Settings to
 * switch the endpoint on is the kind of scavenger hunt that makes an extra feel
 * half-finished.
 */
MODxMCP.panel.Settings = function (config) {
    config = config || {};

    var desc = function (key) {
        return { xtype: 'displayfield', value: _(key), cls: 'desc-under' };
    };

    Ext.applyIf(config, {
        id: 'modxmcp-settings-panel',
        bodyStyle: 'padding:14px',
        border: false,
        labelAlign: 'top',
        items: [{
            xtype: 'fieldset',
            title: _('modxmcp.settings.general'),
            items: [
                { xtype: 'xcheckbox', boxLabel: _('modxmcp.set.enabled'),
                  id: 'modxmcp-set-enabled' },
                desc('modxmcp.set.enabled_desc'),
                { xtype: 'xcheckbox', boxLabel: _('modxmcp.set.log_arguments'),
                  id: 'modxmcp-set-log_arguments' },
                desc('modxmcp.set.log_arguments_desc'),
                { xtype: 'numberfield', fieldLabel: _('modxmcp.set.audit_retention_days'),
                  id: 'modxmcp-set-audit_retention_days', width: 120, allowNegative: false },
                desc('modxmcp.set.audit_retention_days_desc'),
                { xtype: 'numberfield', fieldLabel: _('modxmcp.set.discovery_cache_seconds'),
                  id: 'modxmcp-set-discovery_cache_seconds', width: 120, allowNegative: false },
                desc('modxmcp.set.discovery_cache_seconds_desc'),
                { xtype: 'textfield', fieldLabel: _('modxmcp.set.trusted_proxy_header'),
                  id: 'modxmcp-set-trusted_proxy_header', anchor: '60%' },
                desc('modxmcp.set.trusted_proxy_header_desc')
            ]
        }, {
            xtype: 'fieldset',
            title: _('modxmcp.settings.generic_access'),
            items: [
                { xtype: 'textarea', fieldLabel: _('modxmcp.set.read_class_allowlist'),
                  id: 'modxmcp-set-read_class_allowlist', anchor: '100%', height: 60 },
                desc('modxmcp.set.read_class_allowlist_desc'),
                { xtype: 'textarea', fieldLabel: _('modxmcp.set.write_class_allowlist'),
                  id: 'modxmcp-set-write_class_allowlist', anchor: '100%', height: 60 },
                desc('modxmcp.set.write_class_allowlist_desc')
            ]
        }],
        buttons: [{
            text: _('modxmcp.settings.save'),
            cls: 'primary-button',
            handler: function () { MODxMCP.saveSettings(); }
        }]
    });
    MODxMCP.panel.Settings.superclass.constructor.call(this, config);
};
Ext.extend(MODxMCP.panel.Settings, Ext.FormPanel);
Ext.reg('modxmcp-panel-settings', MODxMCP.panel.Settings);

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
        params[key] = field.getXType() === 'xcheckbox'
            ? (field.getValue() ? 1 : 0)
            : field.getValue();
    });

    MODx.Ajax.request({
        url: MODxMCP.connector(),
        params: params,
        listeners: { success: { fn: function () {
            MODx.msg.status({ title: _('success'), message: _('modxmcp.settings.saved'), delay: 2 });
            // Re-read rather than assume: the server clamps numbers and
            // normalises list separators, so what was typed is not always what
            // ends up stored.
            MODxMCP.loadSettings();
        } } }
    });
};

/** Endpoint URL and live counts, kept in step with whatever was just saved. */
MODxMCP.renderStatus = function (data) {
    var target = Ext.get('modxmcp-status');
    if (!target || !data) { return; }

    var enabledField = Ext.getCmp('modxmcp-set-enabled');
    var enabled = enabledField ? enabledField.getValue() : true;
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
