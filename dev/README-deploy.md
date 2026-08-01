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
2. Set `PKG_VERSION` and `PKG_RELEASE` at the top of `_build/build.transport.php`.
   They are independent of `Server::VERSION`, which is what the MCP handshake
   reports; the changelog tracks the package version.
3. Run `php _build/build.transport.php`.

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

## Testing a deployed site

Both suites talk to a live endpoint over HTTP and need a bearer token:

```sh
php dev/tools-test.php <endpoint-url> <token>
php dev/processor-contract.php <endpoint-url> <token>
```

Create the token by hand in the Manager and revoke it afterwards. There is no
tool and no MCP call that issues one, on purpose: an agent able to mint its own
token could widen its own access.
