#!/bin/sh
# Foreground AI queue group owned by Workerman. Keep the standalone launcher
# available for existing deployments and rollout/rollback.
set -eu
: "${AI_TASK_WORKER_PARENT_PID:?start this group through Workerman}"
: "${PHP_BIN:?Workerman must provide the PHP CLI executable}"

SERVER_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
PID_FILE="$SERVER_DIR/runtime/ai_task_worker.pid"
LOG_DIR="$SERVER_DIR/runtime/log"
LOG_FILE="$LOG_DIR/ai_task_worker.log"
START_LOG="$LOG_DIR/ai_task_worker_start.log"
# Match the exact project Think command. PHP may be invoked as either `php`
# or an absolute Baota PHP path, so the executable itself must not be part of
# the process match.
WORKER_MATCH="$SERVER_DIR/think ai:task-worker"
EPISODE_MATCH="$SERVER_DIR/think short-drama:episode-worker"
PLANNING_MATCH="$SERVER_DIR/think short-drama:planning-worker"
SHORT_DRAMA_AGENT_MATCH="$SERVER_DIR/think short-drama:canvas-agent-worker"
HOME_AGENT_MATCH="$SERVER_DIR/think home-agent:worker"
MICRO_VIDEO_MATCH="$SERVER_DIR/think micro-video:worker"
DECORATION_AGENT_MATCH="$SERVER_DIR/think decoration:agent-worker"
OEM_BRAND_MATCH="$SERVER_DIR/think brand:worker"

mkdir -p "$LOG_DIR"
# Daemonized Workerman may leave fd 2 closed. dash needs a valid stderr
# for its startup pipeline, including when checking a previous launcher's PID.
exec 2>>"$START_LOG"

log_startup() {
    printf '[%s] %s\n' "$(date '+%F %T')" "$*" >> "$START_LOG"
}

log_startup "starting worker: server=$SERVER_DIR php=$PHP_BIN pid=$$"
cd "$SERVER_DIR"
if [ ! -x "$PHP_BIN" ]; then
    log_startup "ERROR: PHP executable not found or not executable: $PHP_BIN"
    exit 127
fi
if [ ! -f "$SERVER_DIR/think" ]; then
    log_startup "ERROR: Think command not found: $SERVER_DIR/think"
    exit 127
fi

# Take ownership before scanning for orphaned children.
if ! command -v flock >/dev/null 2>&1; then
    log_startup "ERROR: Workerman management requires flock"
    exit 1
fi
exec 9>>"$SERVER_DIR/runtime/ai_task_worker.lock"
if ! flock -n 9; then
    log_startup "another AI queue group holds the site lock"
    exit 1
fi

stop_pid() {
    target="$1"
    [ -n "$target" ] || return 0
    case "$target" in *[!0-9]*) return 0 ;; esac
    kill -0 "$target" 2>/dev/null || return 0
    command=$(ps -p "$target" -o command= 2>/dev/null || true)
    case "$command" in
        *"$WORKER_MATCH"*|*"$EPISODE_MATCH"*|*"$PLANNING_MATCH"*|*"$SHORT_DRAMA_AGENT_MATCH"*|*"$HOME_AGENT_MATCH"*|*"$MICRO_VIDEO_MATCH"*|*"$DECORATION_AGENT_MATCH"*|*"$OEM_BRAND_MATCH"*)
            kill -TERM "$target" 2>/dev/null || true
            grace=20
            case "$command" in *"$HOME_AGENT_MATCH"*|*"$MICRO_VIDEO_MATCH"*) grace=1800 ;; esac
            i=0
            while kill -0 "$target" 2>/dev/null && [ "$i" -lt "$grace" ]; do
                case "$(ps -p "$target" -o stat= 2>/dev/null)" in ''|Z*) return 0 ;; esac
                sleep 1
                i=$((i + 1))
            done
            kill -0 "$target" 2>/dev/null && kill -KILL "$target" 2>/dev/null || true
            ;;
    esac
}

if [ -f "$PID_FILE" ]; then
    previous=$(cat "$PID_FILE" 2>/dev/null || true)
    # The standalone supervisor owns its children until the operator retires it.
    if [ "$(readlink "/proc/$previous/cwd" 2>/dev/null)" = "$SERVER_DIR" ]; then
        case "$(ps -p "$previous" -o args= 2>/dev/null)" in
            *start-ai-task-worker.sh*) log_startup "old AI queue launcher still active: pid=$previous"; exit 1 ;;
        esac
    fi
    stop_pid "$previous"
fi

# Only exact commands for this absolute checkout are eligible for termination.
ps -axo pid=,command= | while IFS= read -r line; do
    pid=$(printf '%s\n' "$line" | awk '{print $1}')
    command=${line#"$pid"}
    case "$command" in
        *"$WORKER_MATCH"*|*"$EPISODE_MATCH"*|*"$PLANNING_MATCH"*|*"$SHORT_DRAMA_AGENT_MATCH"*|*"$HOME_AGENT_MATCH"*|*"$MICRO_VIDEO_MATCH"*|*"$DECORATION_AGENT_MATCH"*|*"$OEM_BRAND_MATCH"*) stop_pid "$pid" ;;
    esac
done

rm -f "$PID_FILE"
export AI_TASK_WORKER_PID_FILE="$PID_FILE"
echo "$$" > "$PID_FILE"
log_startup "exec: $PHP_BIN $SERVER_DIR/think ai:task-worker"

pids=""
virtual_pid=""
monitor_sleep=""
# This bounded side loop is deliberately separate from critical queue consumers:
# a payment-channel failure must never restart AI/OEM workers or their tasks.
start_virtual_maintenance() {
    (
        stopping=0
        sleeper=""
        trap 'stopping=1; [ -z "$sleeper" ] || kill -TERM "$sleeper" 2>/dev/null || true' TERM INT
        while [ "$stopping" -eq 0 ] && kill -0 "$$" 2>/dev/null; do
            # Ignore group TERM only for the current bounded operation. The loop
            # stops scheduling and waits for it to finish its durable writes.
            (
                trap '' TERM INT
                # If the launcher is killed, its replacement can recover the AI
                # queues immediately while any previous payment run finishes.
                exec 8>>"$SERVER_DIR/runtime/virtualpay_maintenance.lock"
                flock -n 8 || exit 0
                exec "$PHP_BIN" "$SERVER_DIR/think" virtualpay:maintain
            ) >> "$LOG_FILE" 2>&1 &
            current=$!
            while kill -0 "$current" 2>/dev/null; do wait "$current" || true; done
            [ "$stopping" -eq 0 ] && kill -0 "$$" 2>/dev/null || break
            sleep 60 &
            sleeper=$!
            wait "$sleeper" || true
            sleeper=""
        done
    ) 9>&- &
    virtual_pid=$!
}
cleanup() {
    trap - EXIT
    trap '' TERM INT
    [ -z "$monitor_sleep" ] || kill -TERM "$monitor_sleep" 2>/dev/null || true
    [ -z "$virtual_pid" ] || kill -TERM "$virtual_pid" 2>/dev/null || true
    for pid in $pids; do kill -TERM "$pid" 2>/dev/null || true; done
    for pid in $pids; do wait "$pid" 2>/dev/null || true; done
    [ -z "$virtual_pid" ] || wait "$virtual_pid" 2>/dev/null || true
}
trap 'exit 143' TERM
trap 'exit 130' INT
trap cleanup EXIT

start_worker() {
    # An orphaned long-lived consumer must not keep the launcher's lock.
    "$@" 9>&- >> "$LOG_FILE" 2>&1 &
    pids="$pids $!"
}
start_worker "$PHP_BIN" "$SERVER_DIR/think" ai:task-worker --worker=result --sleep=1 --lease=90 --batch=20
start_worker "$PHP_BIN" "$SERVER_DIR/think" short-drama:episode-worker
start_worker "$PHP_BIN" "$SERVER_DIR/think" short-drama:planning-worker
start_worker "$PHP_BIN" "$SERVER_DIR/think" short-drama:canvas-agent-worker --sleep=2
AGENT_PHP_BIN="$PHP_BIN" start_worker sh "$SERVER_DIR/scripts/home-agent-php.sh" "$SERVER_DIR/think" home-agent:worker
AGENT_PHP_BIN="$PHP_BIN" start_worker sh "$SERVER_DIR/scripts/home-agent-php.sh" "$SERVER_DIR/think" micro-video:worker
start_worker "$PHP_BIN" "$SERVER_DIR/think" decoration:agent-worker --sleep=2
start_worker "$PHP_BIN" "$SERVER_DIR/think" brand:worker --sleep=3
start_virtual_maintenance
log_startup "started workers: $pids; virtual maintenance: $virtual_pid"
# POSIX sh (including dash and older Bash in sh mode) has no wait -n.
# If a critical queue consumer stops, retain the existing group restart policy.
while :; do
    if ! kill -0 "$AI_TASK_WORKER_PARENT_PID" 2>/dev/null; then
        log_startup "Workerman owner exited; draining AI workers"
        exit 1
    fi
    if ! kill -0 "$virtual_pid" 2>/dev/null; then
        wait "$virtual_pid" 2>/dev/null || true
        log_startup "virtual maintenance loop exited; restarting only payment reconciliation"
        start_virtual_maintenance
    fi
    for pid in $pids; do
        if ! kill -0 "$pid" 2>/dev/null; then
            status=0
            wait "$pid" || status=$?
            log_startup "ERROR: worker pid=$pid exited status=$status; restarting group"
            exit 1
        fi
    done
    sleep 1 &
    monitor_sleep=$!
    wait "$monitor_sleep" || true
    monitor_sleep=""
done
