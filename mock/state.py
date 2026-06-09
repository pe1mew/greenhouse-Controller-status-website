import copy
import threading
import time

_lock = threading.Lock()
_STARTED_AT = time.monotonic()

DEFAULTS = {
    'enabled_objects': {
        'climate': True, 'wind': True, 'windows': True,
        'mode':    True, 'sun':  True, 'system':  True,
    },
    'update_interval_s': 10,
    'climate':  {'temp_c': 24.5, 'rh_pct': 72,
                 'temp_max_active': 28,
                 'rh_max_active': 75, 'rh_min_active': 50,
                 'rh_ctrl_enabled': True},
    'wind':     {'speed_ms': 3.5, 'direction_deg': 180},
    'windows':  {'M1': 'OPEN', 'M2': 'MOVING_OPEN', 'M3': 'CLOSED'},
    'mode':     {'current': 'AUTOMATIC', 'flags': []},
    'sun':      {'is_daytime': True, 'sunrise_min': 360, 'sunset_min': 1260},
    'system':   {'ntp_synced': True, 'wifi_ip': '192.168.1.100',
                 'wifi_rssi_dbm': -45, 'fw_ver': '1.17.0',
                 'unit_id': '2344', 'sd_mounted': True,
                 'sd_free_mb': 1875, 'sd_size_mb': 1880},
    'scheduler_running': True,
    'last_response': None,
    # When set to a non-negative integer, build_payload() emits this value as
    # system.uptime_s instead of computing it from the mock process start time.
    # Lets the operator exercise the dashboard's Ns / Nm Ns / Nh Nm / Nd Nh Nm
    # uptime buckets without leaving the mock running for hours.
    'uptime_override_s': None,
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
        # Inject uptime if the system block is being sent. By default it
        # ticks from the mock process start (so the dashboard's Uptime row
        # shows realistic increments across the s / m / h / d buckets). When
        # uptime_override_s is set, that fixed value is emitted instead — used
        # to verify the dashboard's format buckets (e.g. 86400 → "1d 0h 0m").
        if 'system' in p:
            override = state.get('uptime_override_s')
            if isinstance(override, int) and override >= 0:
                p['system']['uptime_s'] = override
            else:
                p['system']['uptime_s'] = int(time.monotonic() - _STARTED_AT)
        # API contract: when rh_ctrl_enabled is False the controller omits
        # rh_min_active / rh_max_active. Mirror that so the dashboard's
        # disabled-state grayout can be exercised end-to-end.
        if 'climate' in p and p['climate'].get('rh_ctrl_enabled') is False:
            p['climate'].pop('rh_min_active', None)
            p['climate'].pop('rh_max_active', None)
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


def toggle_flag(name):
    """Add `name` to mode.flags if absent, remove it if present."""
    with _lock:
        flags = state['mode'].setdefault('flags', [])
        if name in flags:
            flags.remove(name)
        else:
            flags.append(name)


def set_scheduler(on):
    with _lock:
        state['scheduler_running'] = bool(on)


def set_last_response(code, ms):
    with _lock:
        state['last_response'] = {'code': code, 'ms': ms, 'at': time.time()}


def snapshot():
    with _lock:
        return copy.deepcopy(state)
