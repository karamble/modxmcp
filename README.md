# modxmcp — MCP server for MODX 3.x

Exposes a MODX site over the [Model Context Protocol](https://modelcontextprotocol.io), so an AI agent can administer it the way a person would through the Manager.

Requires PHP 8.1+ and MODX 3.0+. Ships no third-party PHP dependencies.

## Why this exists

Tools that edit MODX by saving xPDO objects directly produce content that looks correct and is quietly broken. Saving a resource that way does not fire the Manager's save path, so SeoSuite never registers it and the page is absent from `sitemap.xml`; Collections never applies its rules and the page vanishes from listings; caches are not invalidated.

Every content write in modxmcp goes through a MODX processor instead, which is the same code path the Manager uses. Extras see the change and react to it.

Two paths deliberately do not, because MODX ships no processor for them: generic object access (`modxmcp_object_save`, `modxmcp_object_delete`) and SeoSuite redirects. Each says so in its own response rather than leaving you to find out, and generic object access is off until an administrator allowlists a class.

## Installing

Download `modxmcp-<version>-pl.transport.zip` from the [releases page](https://github.com/karamble/modxmcp/releases) and install it through Package Management, or place it in `core/packages/` and install by signature.

## After installing

Go to **Components → modxmcp**. The page shows your endpoint URL and lets you create a token. That is the whole setup.

The endpoint is live from the moment you install, but a fresh install has no tokens, so every request is rejected until you create one. Creating a token is the deliberate act that grants access; `modxmcp.enabled` exists as a kill switch if you ever need to shut the endpoint off in a hurry.

1. **Components → modxmcp → Tokens → Create token.**

   A token acts as a MODX user and can never do more than that user can do in the Manager. Pick the least privileged account that suffices; do not reach for an administrator out of habit.

   **Important, and counterintuitive:** a user belonging to *no* user group is not a restricted user. MODX treats "no access policy" as unrestricted rather than as denied, so such a user holds every permission, including ones that do not exist. Creating a fresh account with no groups and binding a token to it produces the *most* privileged token possible, not the least.

   modxmcp refuses to act as such a user and will reject the request with an explanation. Put the user in a user group with an access policy, and size that policy to what the agent actually needs.

   The token is shown once and stored hashed. It cannot be recovered.

2. Point your MCP client at the endpoint URL shown on the page, with the token as a bearer credential.

The endpoint is a resource created at install with a random alias, shown on the modxmcp page. It is a resource rather than a PHP file so that modxmcp ships nothing web-accessible, which means it also works on sites that deny PHP execution under `assets/`. Rename its alias if you want a different URL; do not change its content, template or cacheable flag.

## Protocol

Streamable HTTP, no SSE, and no sessions in either era: the token identifies every request.

Two revisions are served from the same endpoint, chosen per request rather than per connection. A request carrying per-request `_meta` is served under 2026-07-28; anything else is treated as an initialization-based client and served under 2025-11-25 semantics, which also covers 2025-06-18 and 2025-03-26.

Both are supported deliberately. Every shipping MCP client still opens with an initialize handshake, so a modern-only server would have nothing able to connect to it.

## Tools

### Resources

| Tool | What it does |
| --- | --- |
| `resource_list` | Browse and search, with filters and a validated sort |
| `resource_get` | One resource, its content and template variable values |
| `resource_create` | Create through the Manager save path |
| `resource_update` | Partial update; the stored row is resubmitted for you |
| `resource_duplicate` | Copy, then re-save so extras register the copy |
| `resource_delete` | Soft delete, recoverable from the recycle bin |

### Elements (chunks, snippets, templates, template variables, plugins)

| Tool | What it does |
| --- | --- |
| `element_list` | Browse by type |
| `element_get` | One element, its body and its bindings |
| `element_save` | Create or update, including what defines a template variable and what a plugin is bound to |
| `element_delete` | Permanent; elements have no recycle bin |
| `category_list` | Categories with per-type element counts |
| `category_save` | Create or rename a category |

### Finding things

| Tool | What it does |
| --- | --- |
| `search` | Literal text across element bodies and resource content, with a `tag_reference` mode that finds every call site of a named element |

### Files

| Tool | What it does |
| --- | --- |
| `file_upload` | One file, base64-encoded, through the Manager upload path, so the media source policy, the upload settings and the file-manager events all apply. Ships disabled: the directory allowlist it is gated on is empty until an administrator fills it in on the Settings tab. Needs the `write:media` token scope. PHP and other server-executable extensions are refused outright, in every dot-segment of the name, and no setting can enable them |

### Orientation and housekeeping

| Tool | What it does |
| --- | --- |
| `site_info` | Versions (including a build fingerprint of the running code), contexts, templates, extras, and advisories for the conditions this site actually exhibits |
| `updates` | What is installed and what is behind, read from the cache the Manager dashboard writes. Cannot install or update |
| `cache_refresh` | Clear caches; rarely needed, since writes invalidate |

### Schema and generic object access

| Tool | What it does |
| --- | --- |
| `schema_list` | Classes every installed extra defines |
| `schema_describe` | Fields, relations and whether access is permitted |
| `object_list` | Read rows of an allowlisted class |
| `object_save` | Write a row of an allowlisted class, outside processors |
| `object_delete` | Delete a row of an allowlisted class |

### Present only where the extra is installed

| Tool | What it does |
| --- | --- |
| `collections_containers` | Collections containers and the rule for their children |
| `seo_redirect` | SeoSuite redirects |
| `migx_describe` | The item structure behind a MIGX template variable |

## Generic object access

Beyond resources and elements, modxmcp can discover and read the data of any installed extra. Schema inspection works out of the box.

Reading or writing actual rows is opt-in per class, via `modxmcp.read_class_allowlist` and `modxmcp.write_class_allowlist`. Both are empty by default, and this is deliberate: xPDO has no permission model, so unlike the resource and element tools there is no MODX permission check behind generic access. Those settings are the only control.

Some classes can never be reached, whatever the settings say: users, sessions, access-control rules, and modxmcp's own tokens and audit log. Fields that look like secrets are masked on read.

## Security notes

- Every call is recorded in the audit log, rejected calls included.
- Tokens support expiry and an IP allowlist.
- Requests must carry a valid token; there is no anonymous access.
- Behind a proxy, set `modxmcp.trusted_proxy_header`, otherwise the recorded client IP is the proxy's. Do not set it if you are not behind a proxy: it would let callers forge the address in the audit log and defeat a token's IP allowlist.
- The Manager page needs `assets/components/modxmcp/connector.php` to be executable. If your site denies PHP under `assets/`, the page will not load its grids. The endpoint is unaffected; manage tokens from the CLI.

## Uninstalling

Removing the package leaves the `modxmcp_token` and `modxmcp_audit` tables in place, because the audit log is a security record and destroying it as a side effect of an uninstall is the wrong default. Drop them by hand if you mean to.

The endpoint resource is also left in place. Delete it manually.

## Developing

`dev/README-deploy.md` covers deploying a working tree to a site over SSH, building the transport package, and testing against a live endpoint. Third-party extras can contribute their own tools through the `OnMCPRegisterTools` event; a failing plugin degrades to its tools being absent, never to a dead endpoint.

## License

[MIT](LICENSE)
