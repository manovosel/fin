#!/usr/bin/env bash
# Print a currently-free TCP port on 127.0.0.1.
# Used by start-server.sh when FIN_BROWSER_PORT is not set.
set -Eeuo pipefail
python3 - <<'PY'
import socket
s = socket.socket()
s.bind(('127.0.0.1', 0))
print(s.getsockname()[1])
s.close()
PY
