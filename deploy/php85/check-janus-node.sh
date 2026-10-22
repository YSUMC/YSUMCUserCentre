#!/usr/bin/env bash
# Read-only checks. APP must have an already compiled Janus tree and installed modules.
set -euo pipefail
[[ $# == 2 ]] || { echo "Usage: $0 ABSOLUTE_NODE ABSOLUTE_JANUS_DIRECTORY" >&2; exit 2; }
NODE=$(realpath -e -- "$1")
APP=$(realpath -e -- "$2")
[[ "$1" == /* && "$2" == /* && -x "$NODE" && -f "$APP/dist/main.js" ]]
[[ $(stat -c %U "$NODE") == root ]]
# Ensure every parent directory and executable resists replacement by the service user.
CHECK="$NODE"
while [[ "$CHECK" != / ]]; do
    [[ $(stat -c %U "$CHECK") == root ]]
    MODE=$(stat -c %a "$CHECK")
    (( (8#$MODE & 0022) == 0 )) || { echo "Writable runtime path: $CHECK" >&2; exit 1; }
    CHECK=$(dirname "$CHECK")
done
runuser -u www -- test -r "$APP/dist/main.js"
cd "$APP"
runuser -u www -- "$NODE" -e '
const fs = require("node:fs");
if (process.version !== "v22.23.4") throw new Error("Unexpected Node version");
for (const name of ["@nestjs/core", "@prisma/client", "oidc-provider"]) {
  fs.accessSync(require.resolve(name), fs.constants.R_OK);
}
console.log(JSON.stringify({node: process.version, executable: process.execPath, modules: "readable", uid: process.getuid()}));
'
