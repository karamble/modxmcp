#!/bin/sh
# deploy.sh — push the working tree to a MODX install over SSH.
#
# Usage:
#     dev/deploy.sh <ssh-host> <web-root> <php-user> [--dry-run]
#
# Example:
#     dev/deploy.sh root@example.com /var/www/example.com/web web44
#
# This is a development deploy, not a release. It rsyncs core/components/modxmcp
# and assets/components/modxmcp over whatever is installed and fixes ownership
# and permissions. That is enough to test a change on a real site, and it is what
# most of this extra's development is done against.
#
# It is NOT a substitute for the transport package. A site deployed this way has
# files newer than the package MODX thinks is installed, so reinstalling or
# upgrading the extra through Package Management silently reverts everything. The
# version check at the end exists to make that drift visible rather than to
# prevent it; see "Building the transport package" in dev/README-deploy.md.
#
# Deliberately does not touch:
#   - core/cache          MODX owns it, and clearing it is a separate decision
#   - the database        no schema or settings changes happen here
#   - dev/ or _build/     neither is part of an installed site

# shellcheck disable=SC2029
# The remote command strings below interpolate $WEB_ROOT and $PHP_USER on the
# client, which is what we want: both are arguments to this script, not values
# read from the remote host. Quoting them for remote expansion would mean the
# remote shell had to know paths it has no way to know.

set -eu

usage() {
    cat <<EOF
Usage: $0 <ssh-host> <web-root> <php-user> [--dry-run]

Arguments:
  <ssh-host>    SSH destination, e.g. root@example.com
  <web-root>    MODX web root on the remote, e.g. /var/www/example.com/web
  <php-user>    User the site runs as, e.g. web44. Files are chowned to it.
  --dry-run     Show what rsync would transfer and change nothing.
EOF
}

if [ "$#" -lt 3 ]; then
    usage >&2
    exit 1
fi

SSH_HOST="$1"
WEB_ROOT="${2%/}"
PHP_USER="$3"
shift 3

DRY_RUN=0
for arg in "$@"; do
    case "$arg" in
        --dry-run) DRY_RUN=1 ;;
        *) echo "Unknown option: $arg" >&2; usage >&2; exit 1 ;;
    esac
done

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CORE_SRC="${REPO_ROOT}/core/components/modxmcp"
ASSETS_SRC="${REPO_ROOT}/assets/components/modxmcp"

for d in "$CORE_SRC" "$ASSETS_SRC"; do
    if [ ! -d "$d" ]; then
        echo "Not found: $d" >&2
        echo "Run this from a modxmcp checkout." >&2
        exit 2
    fi
done

LOCAL_VERSION="$(sed -n "s/.*VERSION *= *'\([^']*\)'.*/\1/p" "${CORE_SRC}/src/Server.php" | head -n 1)"
if [ -z "$LOCAL_VERSION" ]; then
    echo "Could not read Server::VERSION from the working tree." >&2
    exit 2
fi

echo "modxmcp ${LOCAL_VERSION} -> ${SSH_HOST}:${WEB_ROOT}"

# Confirm the target is a MODX install before writing anything into it. A typo in
# the web root would otherwise scatter files into an unrelated directory.
if ! ssh "$SSH_HOST" "test -f '${WEB_ROOT}/config.core.php'"; then
    echo "No config.core.php under ${WEB_ROOT} — that does not look like a MODX web root." >&2
    exit 3
fi

RSYNC_OPTS="-a --delete --omit-dir-times --no-perms"
if [ "$DRY_RUN" -eq 1 ]; then
    RSYNC_OPTS="${RSYNC_OPTS} --dry-run --itemize-changes"
    echo "(dry run)"
fi

# --delete so a file removed from the repo is removed from the site too. Scoped
# to the two component directories, which the package owns outright.
# shellcheck disable=SC2086
rsync $RSYNC_OPTS "${CORE_SRC}/" "${SSH_HOST}:${WEB_ROOT}/core/components/modxmcp/"
# shellcheck disable=SC2086
rsync $RSYNC_OPTS "${ASSETS_SRC}/" "${SSH_HOST}:${WEB_ROOT}/assets/components/modxmcp/"

if [ "$DRY_RUN" -eq 1 ]; then
    echo "Dry run complete. Nothing was changed."
    exit 0
fi

ssh "$SSH_HOST" "
    set -eu
    for d in '${WEB_ROOT}/core/components/modxmcp' '${WEB_ROOT}/assets/components/modxmcp'; do
        chown -R '${PHP_USER}' \"\$d\"
        find \"\$d\" -type f -exec chmod 644 {} +
        find \"\$d\" -type d -exec chmod 755 {} +
    done
"

# Read the version back from the deployed copy rather than trusting the transfer.
# The same closed-loop check the bridge deploy makes: assert the thing that is
# there is the thing that was sent.
REMOTE_VERSION="$(ssh "$SSH_HOST" "sed -n \"s/.*VERSION *= *'\\([^']*\\)'.*/\\1/p\" '${WEB_ROOT}/core/components/modxmcp/src/Server.php' | head -n 1")"

if [ "$REMOTE_VERSION" != "$LOCAL_VERSION" ]; then
    echo "Deployed version is '${REMOTE_VERSION}' but the working tree is '${LOCAL_VERSION}'." >&2
    echo "The transfer did not land as expected." >&2
    exit 4
fi

REMOTE_OWNER="$(ssh "$SSH_HOST" "stat -c '%U' '${WEB_ROOT}/core/components/modxmcp/src/Server.php'")"
if [ "$REMOTE_OWNER" != "$PHP_USER" ]; then
    echo "Deployed files are owned by '${REMOTE_OWNER}', not '${PHP_USER}'." >&2
    echo "PHP may not be able to read them." >&2
    exit 5
fi

cat <<EOF
Deployed ${LOCAL_VERSION}, owned by ${REMOTE_OWNER}.

The site now runs files newer than its installed transport package. Reinstalling
or upgrading modxmcp through Package Management will revert them. Build and
install a package before treating this site as released.
EOF
