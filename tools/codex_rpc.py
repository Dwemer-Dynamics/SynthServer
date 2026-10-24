# SPDX-License-Identifier: GPL-3.0-only
"""Isolated Codex NPC trial. Never reads or copies bearer tokens.

Local stub first verifies model-facing tool/context isolation. --live then uses
Codex's own managed sign-in; no Hermes runtime, memories, or credentials.
"""
import argparse
import json
import os
from pathlib import Path
import queue
import subprocess
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

MODEL = 'gpt-6.1-sol'


def overrides():
    cfg = {"model": MODEL, "model_provider": "openai", "web_search": "disabled", "project_doc_max_bytes": 0,
           "developer_instructions": "", "skills.include_instructions": False, "skills.bundled.enabled": False,
           "tools.update_plan.enabled": False, "tools.experimental_request_user_input.enabled": False,
           "agents.enabled": False, "analytics.enabled": False, "check_for_update_on_startup": False,
           "mcp_servers.node_repl.enabled": False, "include_environment_context": False,
           "include_permissions_instructions": False, "include_apps_instructions": False,
           "include_collaboration_mode_instructions": False}
    flags = ["shell_tool", "unified_exec", "code_mode", "code_mode_host", "apps", "plugins", "remote_plugin", "hooks", "memories", "multi_agent", "multi_agent_v2", "browser_use", "browser_use_external", "computer_use", "in_app_browser", "image_generation", "view_image", "skill_search", "skill_mcp_dependency_install", "tool_suggest", "goals", "sleep_tool", "workspace_dependencies", "daemon_auto_start", "unbounded_connection_retries"]
    for name in flags:
        cfg["features." + name] = False
    cfg["features.skip_host_skill_discovery"] = True
    return cfg


def toml(value):
    if isinstance(value, bool):
        return "true" if value else "false"
    if isinstance(value, dict):
        return "{" + ", ".join(json.dumps(k) + " = " + toml(v) for k, v in value.items()) + "}"
    if isinstance(value, list):
        return "[" + ", ".join(toml(v) for v in value) + "]"
    return json.dumps(value)


class RPC:
    def __init__(self, config, cli, cwd):
        Path(cwd).mkdir(parents=True, exist_ok=True)
        args = [str(cli), "app-server", "--stdio"]
        for k, v in config.items():
            args += ["-c", k + "=" + toml(v)]
        allowed = {"SYSTEMROOT", "WINDIR", "PATH", "PATHEXT", "USERPROFILE", "APPDATA", "LOCALAPPDATA", "TEMP", "TMP", "HOMEDRIVE", "HOMEPATH", "WSL_INTEROP", "WSL_DISTRO_NAME"}
        env = {k: v for k, v in os.environ.items() if k.upper() in allowed}
        self.proc = subprocess.Popen(args, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                     text=True, encoding="utf-8", cwd=cwd, env=env,
                                     creationflags=subprocess.CREATE_NO_WINDOW if os.name == "nt" else 0)
        self.q = queue.Queue(maxsize=1024)
        self.counter = 0
        self.events = []
        self.stderr_lines = 0
        threading.Thread(target=self._read, daemon=True).start()
        threading.Thread(target=self._stderr, daemon=True).start()

    def _stderr(self):
        for _ in self.proc.stderr:
            self.stderr_lines += 1  # Never retain arbitrary auth/runtime diagnostics.

    def _read(self):
        for line in self.proc.stdout:
            try:
                self.q.put(json.loads(line) if len(line) <= 1048576 else {"invalid_protocol": True})
            except ValueError:
                self.q.put({"invalid_protocol": True})

    def send(self, method, params, request=True):
        msg = {"method": method, "params": params}
        if request:
            self.counter += 1
            msg["id"] = self.counter
        self.proc.stdin.write(json.dumps(msg) + "\n")
        self.proc.stdin.flush()
        return msg.get("id")

    def next(self, deadline):
        remaining = deadline - time.monotonic()
        if remaining <= 0:
            raise TimeoutError("trial deadline")
        try:
            msg = self.q.get(timeout=remaining)
        except queue.Empty:
            raise TimeoutError("trial deadline")
        if msg.get("invalid_protocol"):
            raise RuntimeError("invalid app-server protocol")
        if "method" in msg and "id" in msg:
            # No approval, dynamic tool, OAuth refresh, or user-input execution.
            self.proc.stdin.write(json.dumps({"id": msg["id"], "error": {"code": -32601, "message": "NPC trial does not support server requests"}}) + "\n")
            self.proc.stdin.flush()
            raise RuntimeError("unexpected server request: " + msg["method"])
        return msg

    def call(self, method, params, timeout=20):
        request_id = self.send(method, params)
        deadline = time.monotonic() + timeout
        while True:
            msg = self.next(deadline)
            if msg.get("id") == request_id:
                if "error" in msg:
                    raise RuntimeError("RPC " + method + " failed (code " + str(msg["error"].get("code")) + ")")
                return msg["result"]
            self.events.append(msg)

    def close(self):
        if self.proc.poll() is None:
            self.proc.terminate()
        try:
            self.proc.wait(timeout=5)
        except subprocess.TimeoutExpired:
            self.proc.kill()
            self.proc.wait(timeout=5)
