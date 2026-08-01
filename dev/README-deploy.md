# Deploying and packaging modxmcp

Two different things, for two different purposes. Confusing them is how a site
ends up silently reverting.

## Development deploy

`dev/deploy.sh <ssh-host> <web-root> <php-user> [--dry-run]`

rsyncs `core/components/modxmcp` and `assets/components/modxmcp` over whatever is
installed, fixes ownership and modes, then reads `Server::VERSION` back off the
remote and refuses to report success unless it matches the working tree.

```sh
dev/deploy.sh root@example.com /var/www/example.com/web web44 --dry-run
dev/deploy.sh root@example.com /var/www/example.com/web web44
```

It checks for `config.core.php` under the web root first, so a mistyped path
fails before anything is written. It does not touch `core/cache`, the database,
or anything outside the two component directories.

**A site deployed this way has files newer than the transport package MODX
believes is installed.** Reinstalling or upgrading modxmcp through Package
Management overwrites them with the packaged versions, silently. The version
check at the end of the script exists to make that drift visible, not to prevent
it. Anything you intend to keep needs a package.

## Building the transport package

Requires a real MODX install to build against, because `build.transport.php`
instantiates `modX` and uses `modPackageBuilder`. There is usually no MODX on a
development workstation, so the build normally runs on a server.

1. Copy `_build/build.config.sample.php` to `_build/build.config.php` and point
   `MODX_BASE_PATH` at an install. This file is gitignored and never shipped.
2. Set `PKG_VERSION` and `PKG_RELEASE` at the top of `_build/build.transport.php`,
   set `Server::VERSION` to the same version, and add a changelog section for
   `<version>-<release>`. The build checks all three agree and refuses to run
   otherwise: they drifted for five releases, and `1.0.0-beta5` shipped
   reporting `0.4.0`.
3. Run `php _build/build.transport.php`. It prints the build fingerprint of what
   it packaged; keep it to verify the install.

The result lands in the **target install's** `core/packages/`, not in the repo,
because that is where `modPackageBuilder` writes. Copy it out from there.

`build.transport.php` packages `core/components/modxmcp` and
`assets/components/modxmcp` wholesale through file resolvers, so everything under
`src/` is picked up without listing files. `dev/` and `_build/` are not packaged
and never reach an installed site — including `dev/install.php`, which can issue
tokens and is deliberately development-only.

System settings ship with `PRESERVE_KEYS => true, UPDATE_OBJECT => false`, so an
upgrade creates keys that do not exist yet and never overwrites a value an
administrator has set. Adding a setting therefore needs no resolver, but it does
need entries in `_build/data/transport.settings.php`, both
`processors/mgr/settings/*.class.php`, the settings panel JS (the field **and**
`MODxMCP.SETTING_KEYS`), and the lexicon.

## Installing the package

```sh
php dev/package-install.php modxmcp-1.0.0-pl
```

Run it on the target server. It removes anything that would shadow the zip, then
runs `ScanLocal` and `Install` by signature, and reports the build fingerprint of
what ended up installed.

Use it rather than the two processors directly, because **reinstalling a rebuilt
package that kept its signature silently installs the previous build**. Two
mechanisms combine, and neither reports anything:

- `ScanLocal` skips any signature already in `modx_transport_packages`
  (`ScanLocal.php:66-71`), so the row from the previous build is reused along
  with its cached `attributes` and `state`.
- `modTransportPackage::getTransport()` only unzips when the extracted directory
  is missing (`modTransportPackage.php:244-245`):

  ```php
  $state = is_dir($packageDir . $targetDir) ? $this->get('state') : xPDOTransport::STATE_PACKED;
  ```

  With `core/packages/modxmcp-1.0.0-pl/` left over, the new zip is never opened.

The install afterwards reports the right version, because the version is the one
thing that did not change. This is how a site was reverted to beta2 during the
1.0.0 release. Removing the row and the extracted directory first is the whole
of what `package-install.php` adds.

Also install by signature rather than by "whichever modxmcp package is here": a
machine carrying an older zip will otherwise install the older one.

The install is also what registers `OnMCPCollectAdvisories` and any new system
setting, so a site running an rsynced tree has the code without the event row
until a package is actually installed.

## Knowing which build is running

The version number cannot tell you. An rsync changes the files without changing
it, and a rebuilt package keeps it by definition.

`modxmcp_site_info` reports a `modxmcp` object alongside `modx_version`:

```json
"modxmcp": { "version": "1.0.0", "build": "<hash>", "files": 76 }
```

`build` is a hash of every PHP file under `core/components/modxmcp/src`, computed
per request rather than cached, because a cached value would survive an rsync and
then assert the wrong build confidently. `_build/build.transport.php` prints the
same value for what it packaged, and `dev/package-install.php` prints it for what
it installed. Equal fingerprints mean equal code; that is the check, not the
version.

This is why rebuilding `1.0.0-pl` repeatedly during a release is fine and does
not need a version bump.

## Testing a deployed site

Both suites talk to a live endpoint over HTTP and need a bearer token:

```sh
php dev/tools-test.php <endpoint-url> <token>
php dev/processor-contract.php <endpoint-url> <token>
```

Create the token by hand in the Manager and revoke it afterwards. There is no
tool and no MCP call that issues one, on purpose: an agent able to mint its own
token could widen its own access.
