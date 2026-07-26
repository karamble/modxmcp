<?php
/**
 * modxmcp - MCP endpoint snippet.
 *
 * Place an uncached call in a dedicated resource:
 *
 *   template   0        (blank, so nothing wraps the JSON)
 *   cacheable  0        (a cached MCP response would be nonsense)
 *   content    [[!modxmcp]]
 *
 * The resource's alias is the endpoint path. Routing this way means modxmcp
 * ships no web-accessible PHP at all, so it works unchanged on installs that
 * deny PHP execution under assets/.
 *
 * @var \MODX\Revolution\modX $modx
 */

require_once MODX_CORE_PATH . 'components/modxmcp/src/Runtime.php';

\MODXMCP\Runtime::boot();

// MODX already started a session during initialize(): the front controller does
// that whenever anonymous_sessions is on, long before a snippet runs. Undo it
// before any output, while the queued Set-Cookie can still be dropped.
\MODXMCP\Bootstrap::tearDownSession();

\MODXMCP\Runtime::server($modx)->run();

// The response is complete. Returning would let MODX carry on rendering the
// page around it.
exit;
