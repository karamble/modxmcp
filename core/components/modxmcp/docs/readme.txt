modxmcp - MCP Server for MODX 3.x
=================================

Exposes a MODX site over the Model Context Protocol, so an AI agent can
administer it the way a person would through the Manager.

Requires PHP 8.1+ and MODX 3.0+. Ships no third-party PHP dependencies.


WHY THIS EXISTS
---------------

Tools that edit MODX by saving xPDO objects directly produce content that
looks correct and is quietly broken. Saving a resource that way does not
fire the Manager's save path, so SeoSuite never registers it and the page
is absent from sitemap.xml; Collections never applies its rules and the
page vanishes from listings; caches are not invalidated.

Every write in modxmcp goes through a MODX processor instead, which is the
same code path the Manager uses. Extras see the change and react to it.


AFTER INSTALLING
----------------

modxmcp is DISABLED on install and issues no token. Nothing works until you
make two deliberate choices.

1. Components > modxmcp > Tokens > Create token.

   A token acts as a MODX user and can never do more than that user can do
   in the Manager. Pick the least privileged account that suffices; do not
   reach for an administrator out of habit.

   The token is shown once and stored hashed. It cannot be recovered.

2. Set the system setting modxmcp.enabled to Yes.

The endpoint is a resource created at install with a random alias, listed
in Components > modxmcp. It is a resource rather than a PHP file so that
modxmcp ships nothing web-accessible, which means it also works on sites
that deny PHP execution under assets/. Rename its alias if you want a
different URL; do not change its content, template or cacheable flag.

Point your MCP client at that URL with the token as a bearer credential.


PROTOCOL
--------

Implements MCP revision 2026-07-28 (Streamable HTTP) only. That revision is
stateless: no initialize handshake, no sessions, no SSE. Clients speaking
an earlier revision are rejected with a message naming the version this
server supports.


GENERIC OBJECT ACCESS
---------------------

Beyond resources and elements, modxmcp can discover and read the data of
any installed extra. Schema inspection works out of the box.

Reading or writing actual rows is opt-in per class, via
modxmcp.read_class_allowlist and modxmcp.write_class_allowlist. Both are
empty by default, and this is deliberate: xPDO has no permission model, so
unlike the resource and element tools there is no MODX permission check
behind generic access. Those settings are the only control.

Some classes can never be reached, whatever the settings say: users,
sessions, access-control rules, and modxmcp's own tokens and audit log.
Fields that look like secrets are masked on read.


SECURITY NOTES
--------------

- Every call is recorded in the audit log, rejected calls included.
- Tokens support expiry and an IP allowlist.
- Requests must carry a valid token; there is no anonymous access.
- Behind a proxy, set modxmcp.trusted_proxy_header, otherwise the recorded
  client IP is the proxy's. Do not set it if you are not behind a proxy:
  it would let callers forge the address in the audit log and defeat a
  token's IP allowlist.
- The Manager page needs assets/components/modxmcp/connector.php to be
  executable. If your site denies PHP under assets/, the page will not load
  its grids. The endpoint is unaffected; manage tokens from the CLI.


UNINSTALLING
------------

Removing the package leaves the modxmcp_token and modxmcp_audit tables in
place, because the audit log is a security record and destroying it as a
side effect of an uninstall is the wrong default. Drop them by hand if you
mean to.

The endpoint resource is also left in place. Delete it manually.


LINKS
-----

Source and issues: https://github.com/karamble/modxmcp
MCP specification:  https://modelcontextprotocol.io
