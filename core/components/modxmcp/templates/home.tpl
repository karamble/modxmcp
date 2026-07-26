{*
    Manager page template.

    Parsed by SMARTY, not by the MODX parser. MODX tag syntax such as
    [[!+placeholder]] or [[%lexicon.key]] renders literally here; placeholders
    set with setPlaceholder() arrive as Smarty variables, and lexicon strings
    come from $_lang.
*}
{if $enabled}
    <div class="modxmcp-banner modxmcp-banner-ok">
        {$_lang['modxmcp.endpoint']}: <code>{$endpoint|escape:'html'}</code>
    </div>
{else}
    <div class="modxmcp-banner modxmcp-banner-warn">
        {$_lang['modxmcp.disabled_warning']}
    </div>
{/if}

<div id="modxmcp-panel-home"></div>
