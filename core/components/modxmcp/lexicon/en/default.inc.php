<?php
/**
 * modxmcp English lexicon.
 *
 * MODX discovers lexicons under lexicon/<lang>/ automatically, so these need no
 * packaging in the transport build.
 */

$_lang['modxmcp']                     = 'MCP Server';
$_lang['modxmcp.menu.desc']           = 'Manage API tokens and review the MCP audit log.';

// Tokens
$_lang['modxmcp.tokens']              = 'Tokens';
$_lang['modxmcp.token.create']        = 'Create token';
$_lang['modxmcp.token.revoke']        = 'Revoke';
$_lang['modxmcp.token.revoke_confirm'] = 'Revoke this token? Any client using it stops working immediately.';
$_lang['modxmcp.token.name']          = 'Name';
$_lang['modxmcp.token.prefix']        = 'Prefix';
$_lang['modxmcp.token.user']          = 'Acts as';
$_lang['modxmcp.token.user_desc']     = 'The MODX user this token acts as. The token can never do more than this user can do in the Manager, so choose the least privileged account that suffices.';
$_lang['modxmcp.token.scopes']        = 'Scopes';
$_lang['modxmcp.token.scopes_desc']   = 'Checked in addition to the user\'s own permissions, never instead of them.';
$_lang['modxmcp.token.active']        = 'Active';
$_lang['modxmcp.token.expires']       = 'Expires';
$_lang['modxmcp.token.expires_desc']  = 'Optional. Leave empty for a token that does not expire.';
$_lang['modxmcp.token.ip_allowlist']  = 'IP allowlist';
$_lang['modxmcp.token.ip_allowlist_desc'] = 'Optional. Addresses or CIDR ranges, comma or space separated. Empty means any address.';
$_lang['modxmcp.token.last_used']     = 'Last used';
$_lang['modxmcp.token.created']       = 'Created';
$_lang['modxmcp.token.never']         = 'never';
$_lang['modxmcp.token.shown_once']    = 'Copy this token now. It is hashed on save and cannot be shown again.';

// Audit
$_lang['modxmcp.audit']               = 'Audit log';
$_lang['modxmcp.audit.when']          = 'When';
$_lang['modxmcp.audit.method']        = 'Method';
$_lang['modxmcp.audit.tool']          = 'Tool';
$_lang['modxmcp.audit.result']        = 'Result';
$_lang['modxmcp.audit.error']         = 'Error';
$_lang['modxmcp.audit.ip']            = 'IP';
$_lang['modxmcp.audit.duration']      = 'ms';
$_lang['modxmcp.audit.ok']            = 'ok';
$_lang['modxmcp.audit.failed']        = 'failed';
$_lang['modxmcp.audit.clear']         = 'Clear log';
$_lang['modxmcp.audit.clear_confirm'] = 'Delete every audit log entry? This ignores the current filters, cannot be undone, and removes the record of what each token has done.';
$_lang['modxmcp.audit.cleared']       = 'Audit log cleared.';

// Status
$_lang['modxmcp.disabled_warning']    = 'modxmcp is disabled. Set the modxmcp.enabled system setting to Yes to accept requests.';
$_lang['modxmcp.endpoint']            = 'Endpoint';

// Errors
$_lang['modxmcp.err.name_ns']         = 'A name is required.';
$_lang['modxmcp.err.user_ns']         = 'Select the MODX user this token acts as.';
$_lang['modxmcp.err.user_nf']         = 'That MODX user does not exist.';
$_lang['modxmcp.err.token_nf']        = 'Token not found.';

// Added for the token-issuance privilege check.
$_lang['modxmcp.err.user_sudo'] = 'You cannot issue a token for a sudo user unless you are one yourself. Such a token would bypass all access control and be more privileged than your own account.';

// Settings tab. Administration of this extra happens on the extra's own page,
// so every one of these is editable there rather than in System Settings.
$_lang['modxmcp.settings']                  = 'Settings';
$_lang['modxmcp.settings.save']             = 'Save settings';
$_lang['modxmcp.settings.saved']            = 'Settings saved.';
$_lang['modxmcp.settings.general']          = 'General';
$_lang['modxmcp.settings.uploads']          = 'File uploads';
$_lang['modxmcp.settings.generic_access']   = 'Generic object access';

$_lang['modxmcp.set.enabled']               = 'Endpoint enabled';
$_lang['modxmcp.set.enabled_desc']          = 'Turn the MCP endpoint off in a hurry. Access is granted by tokens, not by this switch: with no tokens issued, every request is rejected regardless.';
$_lang['modxmcp.set.log_arguments']         = 'Record tool arguments in the audit log';
$_lang['modxmcp.set.log_arguments_desc']    = 'Off by default. Arguments can contain page content and other caller-supplied data.';
$_lang['modxmcp.set.audit_retention_days']  = 'Audit retention (days)';
$_lang['modxmcp.set.audit_retention_days_desc'] = 'Rows older than this are deleted. 0 keeps everything, which grows without limit because rejected requests are logged too.';
$_lang['modxmcp.set.discovery_cache_seconds'] = 'Schema discovery cache (seconds)';
$_lang['modxmcp.set.discovery_cache_seconds_desc'] = 'How long the scan of installed extras is reused. Lower it if you install extras often.';
$_lang['modxmcp.set.trusted_proxy_header']  = 'Trusted proxy header';
$_lang['modxmcp.set.trusted_proxy_header_desc'] = 'Only set this if the site really is behind a proxy, e.g. X-Forwarded-For. Setting it otherwise lets callers forge the address in the audit log and defeat a token IP allowlist.';
$_lang['modxmcp.set.site_notes']            = 'Site notes for connecting agents';
$_lang['modxmcp.set.site_notes_desc']       = 'Free text returned by the site information call. Use it for local convention nothing can detect: which parent new articles go under, whether changes are live immediately, house style. Prose, not a rule the server enforces. Capped at 8 KB.';
$_lang['modxmcp.set.read_class_allowlist']  = 'Readable classes';
$_lang['modxmcp.set.read_class_allowlist_desc'] = 'Classes generic object reads may touch. Empty means none. Comma or space separated; a trailing * matches a prefix. Unlike the resource and element tools, this path has no MODX permission check behind it, so these lists are the only control.';
$_lang['modxmcp.set.write_class_allowlist'] = 'Writable classes';
$_lang['modxmcp.set.write_class_allowlist_desc'] = 'Classes generic object writes may touch. Empty means none. Users, sessions, access-control rules, system settings, package providers, media sources and modxmcp own tables can never be reached whatever is listed here, and resources and elements can never be written this way because that would bypass the MODX processors.';

$_lang['modxmcp.set.upload_path_allowlist'] = 'Upload directories';
$_lang['modxmcp.set.upload_path_allowlist_desc'] = 'Directories the file upload tool may write into, relative to the media source root. Empty means uploads are disabled. Comma or space separated; a trailing * matches a prefix, e.g. images/uploads/*. The media source access policy and the MODX upload settings still apply on top.';
$_lang['modxmcp.set.upload_extension_allowlist'] = 'Upload extensions';
$_lang['modxmcp.set.upload_extension_allowlist_desc'] = 'File extensions uploads may carry. PHP and other server-executable extensions are blocked outright, in every dot-segment of the name, and cannot be enabled here.';
$_lang['modxmcp.set.upload_max_bytes'] = 'Upload size limit (bytes)';
$_lang['modxmcp.set.upload_max_bytes_desc'] = 'Decoded size cap for one upload. The MODX upload_maxsize system setting applies as well; the stricter of the two wins.';
$_lang['modxmcp.set.upload_source_allowlist'] = 'Upload media sources';
$_lang['modxmcp.set.upload_source_allowlist_desc'] = 'Ids of the media sources uploads may target. The default filesystem source is 1. Run the modxmcp_media_source_list tool to see which sources this site has, where each is rooted and which of them this list covers.';

$_lang['modxmcp.stats.tokens']              = 'Tokens';
$_lang['modxmcp.stats.audit']               = 'Audit rows';
$_lang['modxmcp.stats.failed']              = 'rejected';

// Token scopes, offered as checkboxes rather than a text field: the valid
// values are a closed set only this extra knows.
$_lang['modxmcp.scope.read']              = 'read - inspect resources, elements and schemas';
$_lang['modxmcp.scope.write_content']     = 'write:content - create, update and delete resources';
$_lang['modxmcp.scope.write_elements']    = 'write:elements - create, update and delete chunks, snippets, templates, TVs and plugins';
$_lang['modxmcp.scope.write_media']       = 'write:media - upload files into allowlisted directories';
$_lang['modxmcp.scope.write_media_desc']  = 'Grants nothing on its own: uploads are also gated by the upload directories list on the Settings tab, which is empty by default.';
$_lang['modxmcp.scope.write_objects']     = 'write:objects - generic writes to allowlisted extra classes';
$_lang['modxmcp.scope.write_objects_desc'] = 'Grants nothing on its own: generic access is also gated per class by the writable-classes list on the Settings tab, which is empty by default.';
$_lang['modxmcp.audit.failures_only']     = 'Rejected only';
