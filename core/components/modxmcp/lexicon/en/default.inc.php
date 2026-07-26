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

// Status
$_lang['modxmcp.disabled_warning']    = 'modxmcp is disabled. Set the modxmcp.enabled system setting to Yes to accept requests.';
$_lang['modxmcp.endpoint']            = 'Endpoint';

// Errors
$_lang['modxmcp.err.name_ns']         = 'A name is required.';
$_lang['modxmcp.err.user_ns']         = 'Select the MODX user this token acts as.';
$_lang['modxmcp.err.user_nf']         = 'That MODX user does not exist.';
$_lang['modxmcp.err.token_nf']        = 'Token not found.';
