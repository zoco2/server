#!/usr/bin/env python3
"""Linux integration test. Set WORKERMAN_AUTOLOAD for a separate Composer vendor."""
import json
import os
from pathlib import Path
import shutil
import signal
import subprocess
import tempfile
import threading
import time


ROOT = Path(__file__).resolve().parents[2]
AUTOLOAD = Path(os.environ.get("WORKERMAN_AUTOLOAD", ROOT / "vendor/autoload.php")).resolve()
PHP = os.environ.get("WORKERMAN_PHP", "php")

ENTRY = r"""<?php
require getenv('WORKERMAN_AUTOLOAD');
require_once getenv('FIXTURE_SOURCE') . '/app/common/service/ai/WorkermanAiTaskGroup.php';
use Workerman\Worker;
Worker::$pidFile = __DIR__ . '/runtime/master.pid';
Worker::$logFile = __DIR__ . '/runtime/workerman.log';
Worker::$stdoutFile = __DIR__ . '/runtime/workerman.out';
$action = $argv[1];
app\common\service\ai\WorkermanAiTaskGroup::register(__DIR__, $action, in_array('-d', $argv, true));
if (in_array($action, ['stop', 'restart', 'reload'], true) && !in_array('-g', $GLOBALS['argv'], true)) {
    throw new RuntimeException("$action did not enable graceful mode");
}
Worker::runAll();
"""

FAKE_PHP = r"""#!/usr/bin/env python3
import fcntl, json, os, signal, sys, time
from pathlib import Path
if '-r' in sys.argv:
    sys.exit(0)
args = sys.argv[sys.argv.index(str(Path(os.environ['FIXTURE_SITE']) / 'think')) + 1:]
role = args[0]
if role == 'virtualpay:maintain':
    sys.exit(0)
runtime = Path(os.environ['FIXTURE_SITE']) / 'runtime'
def record(event):
    with (runtime / 'events').open('a') as log:
        log.write(json.dumps([event, role, os.getpid(), os.getppid()]) + '\n')
lock = (runtime / (role + '.lock')).open('a')
try:
    fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
except BlockingIOError:
    record('duplicate')
    sys.exit(1)
def stop(*_):
    signal.signal(signal.SIGTERM, signal.SIG_IGN)
    record('draining')
    if role == 'micro-video:worker':
        time.sleep(2.5)
    record('stopped')
    sys.exit(0)
signal.signal(signal.SIGTERM, stop)
record('started')
while True:
    signal.pause()
"""


def alive(pid):
    try:
        return Path(f"/proc/{pid}/stat").read_text().rsplit(") ", 1)[1][0] != "Z"
    except FileNotFoundError:
        return False


def until(check, description, timeout=35):
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        result = check()
        if result:
            return result
        time.sleep(0.05)
    raise AssertionError(f"Timed out waiting for {description}")


def run(site):
    runtime = site / "runtime"
    scripts = site / "scripts"
    runtime.mkdir()
    scripts.mkdir()
    (site / "think").touch()
    (site / "test-worker.php").write_text(ENTRY)
    (site / "fake-php").write_text(FAKE_PHP)
    (site / "fake-php").chmod(0o755)
    # Only shorten the idle payment-loop wait after a hard launcher crash.
    (site / "sleep").write_text('#!/bin/sh\nif [ "$1" = 60 ]; then set -- 0.2; fi\nexec /bin/sleep "$@"\n')
    (site / "sleep").chmod(0o755)
    for name in ("start-ai-task-worker.sh", "start-workerman-ai-task-group.sh", "home-agent-php.sh"):
        shutil.copy(ROOT / "scripts" / name, scripts)
    env = dict(os.environ, WORKERMAN_AUTOLOAD=str(AUTOLOAD), FIXTURE_SITE=str(site),
               FIXTURE_SOURCE=str(ROOT), PHP_BIN=str(site / "fake-php"), PATH=f"{site}:{os.environ['PATH']}")
    command = [PHP, str(site / "test-worker.php")]
    processes = []

    def events():
        path = runtime / "events"
        return [json.loads(line) for line in path.read_text().splitlines()] if path.exists() else []

    def next_group(previous=0):
        def ready():
            for event, role, pid, launcher in reversed(events()):
                if event != "started" or role != "micro-video:worker" or launcher == previous or not alive(pid):
                    continue
                try:
                    fields = Path(f"/proc/{launcher}/stat").read_text().rsplit(") ", 1)[1].split()
                except FileNotFoundError:
                    continue
                parent = int(fields[1])
                if fields[0] != "Z" and parent > 1 and alive(parent):
                    return launcher, parent
            return None
        return until(ready, "a live replacement micro-video worker")

    def invoke(action):
        result = subprocess.run(command + [action], cwd=site, env=env, text=True,
                                stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=40)
        if result.returncode:
            raise AssertionError(f"{action} failed: {result.stdout}")
        return result.stdout

    with (runtime / "commands.log").open("w") as output:
        def start(action):
            process = subprocess.Popen(command + [action], cwd=site, env=env, stdout=output,
                                       stderr=subprocess.STDOUT, start_new_session=True)
            processes.append(process)
            # Workerman's restart/stop CLI waits for the old PID to disappear, including zombies.
            threading.Thread(target=process.wait, daemon=True).start()
            return process
        launcher = ["/bin/sh", str(scripts / "start-ai-task-worker.sh")]
        standalone = subprocess.Popen(launcher, cwd=site, env=env, stdout=output, stderr=subprocess.STDOUT,
                                      start_new_session=True)
        try:
            original, _ = next_group()
            start("start")
            until(lambda: "old AI queue launcher still active" in
                  (runtime / "log/ai_task_worker_start.log").read_text(), "Workerman deferring to standalone owner")
            assert standalone.poll() is None and original == standalone.pid
            standalone.terminate()
            standalone.wait(timeout=10)
            shell, adapter = next_group(original)
            assert "ai-task-group" in invoke("status")
            active = [pid for event, _, pid, owner in events() if event == "started" and owner == shell]
            second = subprocess.run(["/bin/sh", str(scripts / "start-workerman-ai-task-group.sh")], cwd=site,
                                    env=dict(env, AI_TASK_WORKER_PARENT_PID=str(os.getpid())),
                                    stdout=output, stderr=subprocess.STDOUT, timeout=8)
            assert second.returncode != 0 and all(map(alive, active)), "second managed launcher disturbed live group"
            assert "another AI queue group holds the site lock" in (runtime / "log/ai_task_worker_start.log").read_text()
            os.kill(shell, signal.SIGKILL)
            shell, adapter = next_group(shell)
            os.kill(adapter, signal.SIGKILL)
            shell, adapter = next_group(shell)
            master = start("restart")
            old_shell = shell
            shell, adapter = next_group(old_shell)
            assert not alive(old_shell)
            assert int((runtime / "master.pid").read_text()) == master.pid, "restart daemonized a foreground master"
            invoke("reload")
            shell, adapter = next_group(shell)
            assert int((runtime / "master.pid").read_text()) == master.pid, "reload replaced the master"
            invoke("stop")
            until(lambda: not alive(shell) and not alive(adapter), "stop and drain")
            master = start("start")
            shell, adapter = next_group(shell)
            os.killpg(master.pid, signal.SIGTERM)
            until(lambda: not alive(shell) and not alive(adapter), "process-group TERM and drain")
            for process in processes:
                assert process.wait(timeout=8) == 0
            log = events()
            assert not any(event == "duplicate" for event, *_ in log), log
            for event, role, pid, _ in log:
                if event == "started":
                    assert not alive(pid), f"{role} PID {pid} survived stop"
                    if role == "micro-video:worker":
                        assert any(e == "stopped" and p == pid for e, _, p, _ in log), f"lost drain for {pid}"
        except BaseException:
            output.flush()
            print((runtime / "commands.log").read_text(), flush=True)
            for file in ("workerman.log", "log/ai_task_worker_start.log", "log/ai_task_worker.log", "events"):
                if (runtime / file).is_file():
                    print(file, (runtime / file).read_text()[-5000:], flush=True)
            raise
        finally:
            # Every foreground master and all of its descendants belong to a new test-only session.
            for process in [standalone, *processes]:
                try:
                    os.killpg(process.pid, signal.SIGKILL)
                except ProcessLookupError:
                    pass
                process.wait(timeout=5)


if __name__ == "__main__":
    if not AUTOLOAD.is_file():
        raise SystemExit("Set WORKERMAN_AUTOLOAD to vendor/autoload.php containing workerman/workerman")
    with tempfile.TemporaryDirectory(prefix="workerman-ai-group-") as directory:
        run(Path(directory))
    print("PASS: Workerman status, launcher/adapter crash recovery, restart, and stop")
