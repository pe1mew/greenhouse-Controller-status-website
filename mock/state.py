import copy
import threading
import time

_lock = threading.Lock()

DEFAULTS = {
    'enabled_objects': {
        'climate': True, 'wind': True, 'windows': True,
        'mode':    True, 'sun':  True, 'system':  True,
    },
    'update_interval_s': 10,
    'climate':  {'temp_c': 24.5, 'rh_pct': 72},
    'wind':     {'speed_ms': 3.5, 'direction_deg': 180},
    'windows':  {'M1': 'OPEN', 'M2': 'MOVING_OPEN', 'M3': 'CLOSED'},
    'mode':     {'current': 'AUTOMATIC', 'flags': []},
    'sun':      {'is_daytime': True, 'sunrise_min': 360, 'sunset_min': 1260},
    'system':   {'ntp_synced': True, 'wifi_ip': '192.168.1.100',
                 'wifi_rssi_dbm': -45, 'fw_ver': '1.17.0'},
    'scheduler_running': True,
    'last_response': None,
}

state = copy.deepcopy(DEFAULTS)


def build_payload():
    with _lock:
        p = {'type': 'status'}
        if state['update_interval_s'] is not None:
            p['update_interval_s'] = state['update_interval_s']
        for name, on in state['enabled_objects'].items():
            if on:
                p[name] = copy.deepcopy(state[name])
        return p


def set_field(path, value):
    parts = path.split('.')
    with _lock:
        if len(parts) == 1:
            state[parts[0]] = value
            return
        obj = state
        for k in parts[:-1]:
            obj = obj[k]
        obj[parts[-1]] = value


def toggle_object(name):
    with _lock:
        if name in state['enabled_objects']:
            state['enabled_objects'][name] = not state['enabled_objects'][name]


def set_scheduler(on):
    with _lock:
        state['scheduler_running'] = bool(on)


def set_last_response(code, ms):
    with _lock:
        state['last_response'] = {'code': code, 'ms': ms, 'at': time.time()}


def snapshot():
    with _lock:
        return copy.deepcopy(state)
