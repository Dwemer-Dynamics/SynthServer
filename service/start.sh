#!/bin/bash

SERVER_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# Share the PHP probe's default and existing environment override; never bind Dialectic's port by accident.
PORT="$(php -r 'require $argv[1]; echo synthBackgroundProcessorPort();' "$SERVER_ROOT/lib/background_processor.php")" || exit 1
SERVICE_LOG="$SERVER_ROOT/log/service.log"
FALLBACK_SERVICE_LOG="/tmp/synth_service.log"
LOG_DIR="$(dirname "$SERVICE_LOG")"
STATE_DIR="$(php -r 'require $argv[1]; echo synthBackgroundProcessorStateDirectory();' "$SERVER_ROOT/lib/background_processor.php")" || exit 1
mkdir -p "$STATE_DIR" || exit 1
LOCK_FILE="$STATE_DIR/synth_background_processor.lock"

# Ensure new files are group-readable/writable for dwemer + www-data workflows.
umask 0002

# Prevent concurrent web requests from starting duplicate manager loops before
# the heartbeat listener has finished binding its port.
exec 9>"$LOCK_FILE"
if ! flock -n 9; then
    echo "An instance of the SYNTH background processor is already starting or running."
    exit 1
fi

mkdir -p "$LOG_DIR" 2>/dev/null
if ! touch "$SERVICE_LOG" 2>/dev/null; then
    SERVICE_LOG="$FALLBACK_SERVICE_LOG"
    touch "$SERVICE_LOG" 2>/dev/null || true
fi

# Check if port is in use
if nc -z 127.0.0.1 "$PORT" 2>/dev/null; then
    echo "An instance of the script is already running."
    exit 1
fi

# Keep one socket bound for the lifetime of the manager. The previous one-shot
# listener briefly released the port after every health probe, which produced
# false connection-refused warnings while the processor was healthy.
nc -lk -s 127.0.0.1 -p "$PORT" </dev/null &>/dev/null 9>&- &
LISTENER_PID=$!

# Bound termination of an owned child, including one stopped or stuck outside PHP signal handling.
stop_service_child() {
    local child_pid="$1"
    local stop_ticks="${2:-20}"
    local stop_tick
    [[ "$child_pid" =~ ^[1-9][0-9]*$ ]] || return
    kill "$child_pid" 2>/dev/null
    for ((stop_tick=0; stop_tick<stop_ticks; stop_tick++)); do
        if ! kill -0 "$child_pid" 2>/dev/null; then break; fi
        sleep 0.05
    done
    if kill -0 "$child_pid" 2>/dev/null; then kill -KILL "$child_pid" 2>/dev/null; fi
    wait "$child_pid" 2>/dev/null
    return 0
}

# Speech deadlines cannot wait for slower profile/memory tasks in the manager.
(
    exec 9>&-
    trap 'stop_service_child "$tts_pid"; exit' TERM INT
    while true; do
        php "$SERVER_ROOT/service/tts_dispatch.php" --loop &>> "$SERVICE_LOG" &
        tts_pid=$!
        wait "$tts_pid"
        tts_pid=""
        sleep 1 &
        tts_pid=$!
        wait "$tts_pid"
        tts_pid=""
    done
) &
TTS_SUPERVISOR_PID=$!

MANAGER_PID=""
SLEEP_PID=""
# Stop and reap the children this supervisor owns, even while a manager tick is busy.
cleanup_service_children() {
    trap - EXIT TERM INT
    for child_pid in "$LISTENER_PID" "$TTS_SUPERVISOR_PID" "$MANAGER_PID" "$SLEEP_PID"; do
        if [[ "$child_pid" =~ ^[1-9][0-9]*$ ]]; then kill "$child_pid" 2>/dev/null; fi
    done
    for child_pid in "$LISTENER_PID" "$TTS_SUPERVISOR_PID" "$MANAGER_PID" "$SLEEP_PID"; do
        # The nested speech supervisor has its own one-second child shutdown bound.
        stop_service_child "$child_pid" 40
    done
}
trap cleanup_service_children EXIT
trap 'exit 0' TERM INT

# Main loop
while true; do 
    php "$SERVER_ROOT/service/manager.php" &>> "$SERVICE_LOG" 9>&- &
    MANAGER_PID=$!
    wait "$MANAGER_PID"
    MANAGER_PID=""
    sleep 5 &
    SLEEP_PID=$!
    wait "$SLEEP_PID"
    SLEEP_PID=""
done
