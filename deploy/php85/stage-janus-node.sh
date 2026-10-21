#!/usr/bin/env bash
# Extract a verified runtime into a NEW versioned directory. Does not touch systemd.
set -euo pipefail
[[ $# == 2 ]] || { echo "Usage: $0 NODE_ARCHIVE ABSOLUTE_RUNTIME_PARENT" >&2; exit 2; }
[[ $(id -u) == 0 && $(uname -m) == x86_64 && $(uname -s) == Linux ]]
ARCHIVE=$(realpath -- "$1")
PARENT=$(realpath -m -- "$2")
[[ "$2" == /* && "$PARENT" != / && "$PARENT" != /usr && "$PARENT" != /usr/bin ]]
VERSION=node-v22.23.4-linux-x64
EXPECTED=1805bcd35e6815559bd2eb97db438d3fdb0eb3a5e6a977b7c1badca76337e9ce
[[ $(sha256sum -- "$ARCHIVE" | cut -d' ' -f1) == "$EXPECTED" ]] || { echo 'Node archive SHA-256 mismatch' >&2; exit 1; }
[[ ! -e "$PARENT/$VERSION" && ! -L "$PARENT/$VERSION" ]] || { echo 'Refusing to overwrite an existing runtime' >&2; exit 1; }
install -d -o root -g root -m 0755 "$PARENT"
tar --extract --xz --file="$ARCHIVE" --directory="$PARENT" --no-same-owner
chown -R root:root "$PARENT/$VERSION"
chmod -R go-w "$PARENT/$VERSION"
[[ $(runuser -u www -- "$PARENT/$VERSION/bin/node" --version) == v22.23.4 ]]
echo "Verified runtime: $PARENT/$VERSION/bin/node"
echo 'No service configuration changed; no daemon-reload or restart performed.'
