{*
    Manager page template.

    Deliberately almost empty, matching how every other MODX extra does this:
    the page is assembled by sections/home.js, which the controller loads with
    addLastJavascript so it runs after the inline config block.

    Note this file is parsed by Smarty, not by the MODX parser. MODX tag syntax
    such as [[!+placeholder]] renders literally here.
*}
<div id="modxmcp-status"></div>
<div id="modxmcp-panel-home-div"></div>
