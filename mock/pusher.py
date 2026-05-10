import os
import sys
import threading
import time

import requests

from . import state


def _load_deploy_env():
    here = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    path = os.path.join(here, '.deploy.env')
    if not os.path.isfile(path):
        return
    with open(path, encoding='utf-8') as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith('#') or '=' not in line:
                continue
            k, v = line.split('=', 1)
            os.environ.setdefault(k.strip(), v.strip())


_load_deploy_env()

TARGET = os.environ.get('MOCK_TARGET_BASE_URL')
if not TARGET:
    sys.exit(
        "MOCK_TARGET_BASE_URL is not set.\n"
        "  Set it in .deploy.env at the project root, or export it before launching.\n"
        "  Example: http://192.168.20.232/controller"
    )

SECRET = os.environ.get('MOCK_SECRET', 'dev-1234567890abcdef-please-rotate-in-prod')
INTERVAL = int(os.environ.get('MOCK_INTERVAL_S', '10'))


def _post(url, *, json=None, data=None, headers=None, secret=None):
    h = {'sourceidentifier': SECRET if secret is None else secret}
    if headers:
        h.update(headers)
    t0 = time.time()
    try:
        r = requests.post(url, json=json, data=data, headers=h, timeout=5)
        ms = int((time.time() - t0) * 1000)
        state.set_last_response(r.status_code, ms)
        return r.status_code, ms
    except requests.RequestException:
        ms = int((time.time() - t0) * 1000)
        state.set_last_response(0, ms)
        return 0, ms


def push_status_now():
    return _post(f'{TARGET}/api.php', json=state.build_payload())


def push_malformed():
    return _post(f'{TARGET}/api.php', data='not-json',
                 headers={'Content-Type': 'application/json'})


def push_bad_secret():
    return _post(f'{TARGET}/api.php', json=state.build_payload(), secret='WRONG')


def upload_log(path):
    with open(path, 'rb') as f:
        body = f.read()
    return _post(f'{TARGET}/api.php?action=log', data=body,
                 headers={'Content-Type': 'text/plain'})


_thread = None
_stop = threading.Event()


def _scheduler_loop():
    while not _stop.is_set():
        snap = state.snapshot()
        if snap['scheduler_running']:
            try:
                push_status_now()
            except Exception:
                pass
        _stop.wait(INTERVAL)


def start_scheduler():
    global _thread
    if _thread is not None and _thread.is_alive():
        return
    _stop.clear()
    _thread = threading.Thread(target=_scheduler_loop, daemon=True)
    _thread.start()


def stop_scheduler_thread():
    _stop.set()
