#!/usr/bin/env bash
# SPDX-License-Identifier: GPL-3.0-only
set -euo pipefail
runtime=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
private="$HOME/.local/share/synth-stu"
script="$runtime/installed/codex_bridge.py"
pidfile="$private/codex-bridge.pid"
test -d "$private"
test -f "$script"
exec 9>"$private/codex-bridge.lock"
if ! flock -n 9; then
    echo 'Owned SYNTH ChatGPT bridge already running.'
    exit 0
fi
umask 077
echo "$$" > "$pidfile"
# Startup discovers the installed executable, proves isolation, and requires a real ChatGPT reply.
exec python3 "$script" --config "$private/codex-bridge.json"
