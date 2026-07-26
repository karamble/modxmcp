{*
    Manager page template.

    Parsed by SMARTY, not by the MODX parser. MODX tag syntax such as
    [[!+placeholder]] or [[%lexicon.key]] renders literally here; placeholders
    set with setPlaceholder() arrive as Smarty variables, and lexicon strings
    come from $_lang.

    The banner below is a server-rendered fallback so the page says something
    useful before the JavaScript runs. Once loaded, the Settings tab replaces
    the contents of #modxmcp-status with live values, because settings are
    editable on this page and a banner rendered once at page load would go stale
    the moment anything was saved.
*}
<div id="modxmcp-status">
    {if $enabled}
        <div class="modxmcp-banner modxmcp-banner-ok">
            {$_lang['modxmcp.endpoint']}: <code>{$endpoint|escape:'html'}</code>
        </div>
    {else}
        <div class="modxmcp-banner modxmcp-banner-warn">
            {$_lang['modxmcp.disabled_warning']}
        </div>
    {/if}
</div>

<div id="modxmcp-panel-home"></div>
