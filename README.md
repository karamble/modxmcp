# modxmcp — MCP server for MODX 3.x

Exposes a MODX site over the [Model Context Protocol](https://modelcontextprotocol.io), so an AI agent can administer it the way a person would through the Manager.

Requires PHP 8.2+ and MODX 3.0+. Ships no third-party PHP dependencies.

## Why this exists

Tools that edit MODX by saving xPDO objects directly produce content that looks correct and is quietly broken. Saving a resource that way does not fire the Manager's save path, so SeoSuite never registers it and the page is absent from `sitemap.xml`; Collections never applies its rules and the page vanishes from listings; caches are not invalidated.

Every content write in modxmcp goes through a MODX processor instead, which is the same code path the Manager uses. Extras see the change and react to it.

Two paths deliberately do not, because MODX ships no processor for them: generic object access (`modxmcp_object_save`, `modxmcp_object_delete`) and SeoSuite redirects, which are written directly and gated on SeoSuite's own `seosuite` permission. Each says so in its own response rather than leaving you to find out, and generic object access is off until an administrator allowlists a class.

## Installing

Download `modxmcp-<version>-pl.transport.zip` from the [releases page](https://github.com/karamble/modxmcp/releases) and install it through Package Management, or place it in `core/packages/` and install by signature.

## After installing

Go to **Components → modxmcp**. The page shows your endpoint URL and lets you create a token. That is the whole setup.

The endpoint is live from the moment you install, but a fresh install has no tokens, so every request is rejected until you create one. Creating a token is the deliberate act that grants access; `modxmcp.enabled` exists as a kill switch if you ever need to shut the endpoint off in a hurry.

1. **Components → modxmcp → Tokens → Create token.**

   A token acts as a MODX user and can never do more than that user can do in the Manager. Every tool asks MODX for the permission the Manager requires for the same action before it reads or writes anything, and a resource or element under a resource group or category policy is refused or left out of lists, as it is in the Manager. Which permission each tool asks for is listed under [Security notes](#security-notes). Pick the least privileged account that suffices; do not reach for an administrator out of habit. A token bound to a sudo user is limited by nothing but its scopes, as sudo is in the Manager.

   A user belonging to *no* user group has no access policy on the manager context, and modxmcp cannot tell a deliberately locked-down account from a misconfigured one, so it refuses to bind either and rejects the request with an explanation. (MODX would deny such a user in any case, once policies are evaluated: the manager context carries access rules the user matches none of.) Put the user in a user group with an access policy, and size that policy to what the agent actually needs. With the stock policies, Load Only lets an agent call almost nothing, and Content Editor lets it read resources and templates and edit resources, but not read chunks, snippets, template variables or plugins.

   The token is shown once and stored hashed. It cannot be recovered.

2. Point your MCP client at the endpoint URL shown on the page, with the token as a bearer credential.

The endpoint is a resource created at install with a random alias, shown on the modxmcp page. It is a resource rather than a PHP file so that modxmcp ships nothing web-accessible, which means it also works on sites that deny PHP execution under `assets/`. Rename its alias if you want a different URL; do not change its content, template or cacheable flag.

A standalone PHP endpoint, `assets/components/modxmcp/mcp.php`, exists for sites that would rather point a client at a conventional connector file. It ships **disabled**: until you set the `modxmcp.enable_standalone_endpoint` system setting to Yes it returns 404, so a site that only uses the resource route exposes no web-accessible PHP it did not choose. The resource route is always available regardless.

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
| `category_delete` | Remove a category, refusing one that still holds elements or sub-categories, and naming what is in it. Stricter than MODX, which deletes it regardless and silently uncategorises the contents |

### Finding things

| Tool | What it does |
| --- | --- |
| `search` | Literal text across element bodies and resource content, with a `tag_reference` mode that finds every call site of a named element |

### Files

| Tool | What it does |
| --- | --- |
| `media_source_list` | The media sources this site defines, with the id `file_upload` expects, the filesystem path and public URL each is rooted at, and whether uploads to it are permitted. Credentials are never returned |
| `file_upload` | One file, base64-encoded, through the Manager upload path, so the media source policy, the upload settings and the file-manager events all apply. Ships disabled: the directory allowlist it is gated on is empty until an administrator fills it in on the Settings tab. Needs the `write:media` token scope. PHP and other server-executable extensions are refused outright, in every dot-segment of the name, and no setting can enable them |

`modxmcp.upload_path_allowlist` is relative to the media source, not to `assets/`. That is easy to miss, because the Filesystem source MODX ships has no configured base path and therefore resolves to the webroot: with `images/*` allowlisted, an upload to `images/` against that source lands in `<webroot>/images/`, nowhere near `assets/`. A source whose base path is `assets/images/products/` puts the same upload at `<webroot>/assets/images/products/images/`. One allowlist entry, two very different places.

So check the base path of the source you mean to use before writing the allowlist, and pass `source` explicitly rather than inheriting the default of 1. `media_source_list` is how to check: it reports each source's resolved base path and base URL, and whether the allowlist currently permits uploading to it. The `url` in an upload result is resolved from the source itself and is the authoritative answer to where the file actually went.

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

Reading or writing actual rows is opt-in per class, via `modxmcp.read_class_allowlist` and `modxmcp.write_class_allowlist`. Both are empty by default, and this is deliberate: xPDO has no permission model, so generic access has no MODX permission check of its own, and those settings are its main control. Two things still apply on top. A resource or element class on the read list is held to the same Manager permission the dedicated tools ask for, so the allowlist cannot become a second way around them. And a row that carries an access policy of its own, such as a resource in a resource group, is subject to it on read, save and delete, as in the Manager.

Some classes can never be reached through generic access, whatever the settings say: users, sessions, access-control rules, system settings, package providers, media sources, and modxmcp's own tokens and audit log. Fields that look like secrets are masked on read, and where that is not enough the class is blocked outright — a media source keeps its S3 or FTP credentials inside one serialised column, which field-name masking cannot see into. Media sources are instead readable through `media_source_list`, which returns everything about them except those credentials.

## Security notes

- Every tool asks MODX for the permission the Manager requires for the same action, before reading or writing anything, and a refusal names the permission and how to grant it:
  - resources (`resource_list`, `resource_get`, `collections_containers`): `view`, which is what MODX's own resource processors declare. The Manager's resource page additionally requires `view_document`.
  - elements (`element_list`, `element_get`): `view_chunk`, `view_snippet`, `view_template`, `view_tv` or `view_plugin`, by type. `migx_describe` asks `view_tv`.
  - `search` asks the same names for whatever it covers, skips a kind the user may not read and says so, and refuses only when nothing that was asked for remains. It deliberately does not ask the Manager's `search` permission, which gates the title search screen and is not in the stock Content Editor policy: it reads only what the user could already open one at a time.
  - `updates`: `packages`, as the Manager's package grid. The Updates dashboard widget is gated on `workspaces` instead, so an operator can hold one without the other.
  - `media_source_list`: `source_view`. `cache_refresh`: `empty_cache`. `seo_redirect`: `seosuite`.
  - the write tools ask their MODX processor's own check first, so the names are MODX's own. `resource_delete` asks `delete_document` by name, because MODX's Resource/Delete processor does not check it until after it has looked the resource up.
  - `category_list`, `schema_list` and `schema_describe` ask nothing: MODX's own category list declares no permission, and the schema tools read model metadata, not rows. `site_info` asks nothing either, since it is what tells an agent what it may do, but it withholds templates and any advisory built from data the user may not see, and reports a `permissions` map so refusals can be predicted.
- Resources and elements can carry access policies of their own, through resource groups and element categories. A single read of one the user may not view is refused; lists leave such rows out and report how many with `hidden`; search leaves them out silently, since counting them would reveal that something matched.
- MODX evaluates access policies only inside a session it started itself; in any other state every permission check answers yes. modxmcp lets MODX start a session on every request, on both routes, and discards it before responding, so no cookie is issued. A request on which MODX would still not evaluate policies is refused for a non-sudo token rather than served without them.
- Every call is recorded in the audit log, rejected calls included.
- Tokens support expiry and an IP allowlist.
- Requests must carry a valid token; there is no anonymous access.
- Behind a proxy, set `modxmcp.trusted_proxy_header`, otherwise the recorded client IP is the proxy's. Do not set it if you are not behind a proxy: it would let callers forge the address in the audit log and defeat a token's IP allowlist.
- The optional standalone endpoint `assets/components/modxmcp/mcp.php` ships disabled and returns 404 until you set `modxmcp.enable_standalone_endpoint`. Leave it off unless you deliberately use it, so no site exposes web-accessible PHP it never chose; the resource route is unaffected either way.
- The Manager page needs `assets/components/modxmcp/connector.php` to be executable. If your site denies PHP under `assets/`, the page will not load its grids. The endpoint is unaffected; manage tokens from the CLI.

## Uninstalling

Removing the package leaves the `modxmcp_token` and `modxmcp_audit` tables in place, because the audit log is a security record and destroying it as a side effect of an uninstall is the wrong default. Drop them by hand if you mean to.

The endpoint resource is also left in place. Delete it manually.

## Developing

`dev/README-deploy.md` covers deploying a working tree to a site over SSH, building the transport package, and testing against a live endpoint. Third-party extras can contribute their own tools through the `OnMCPRegisterTools` event; a failing plugin degrades to its tools being absent, never to a dead endpoint. A tool a plugin registers is responsible for its own MODX permission check, as a processor is: the registry checks only the token's scope. Tools extending `AbstractTool` inherit `requirePermission()`, `requirePolicy()` and `requireProcessorPermission()` for it. Registering a name that already exists replaces that tool in place, and `ToolRegistry::get()` returns the instance to delegate to, so an extra can wrap a built-in tool with rules of its own.

## License

[MIT](LICENSE)
