import os

from flask import Flask, redirect, render_template, request, url_for

from . import pusher, state

app = Flask(__name__, static_folder='static', template_folder='templates')

SAMPLE_LOG = os.path.join(os.path.dirname(__file__), 'sample.log')


def _coerce(s):
    if s == '':
        return None
    if s.lower() == 'true':
        return True
    if s.lower() == 'false':
        return False
    try:
        if '.' in s:
            return float(s)
        return int(s)
    except ValueError:
        return s


@app.route('/')
def control():
    return render_template(
        'control.html',
        state=state.snapshot(),
        target=pusher.get_target(),
        target_error=request.args.get('target_error'),
        interval=pusher.INTERVAL,
    )


@app.route('/set', methods=['POST'])
def set_field():
    state.set_field(request.form['path'], _coerce(request.form['value']))
    return redirect(url_for('control'))


@app.route('/target', methods=['POST'])
def set_target():
    try:
        pusher.set_target(request.form.get('target', ''))
    except ValueError as e:
        return redirect(url_for('control', target_error=str(e)))
    return redirect(url_for('control'))


@app.route('/toggle/<name>', methods=['POST'])
def toggle(name):
    state.toggle_object(name)
    return redirect(url_for('control'))


@app.route('/mode/flag/<name>', methods=['POST'])
def toggle_flag(name):
    state.toggle_flag(name)
    return redirect(url_for('control'))


@app.route('/window/<wid>', methods=['POST'])
def set_window(wid):
    state.set_field(f'windows.{wid}', request.form['state'])
    return redirect(url_for('control'))


@app.route('/push', methods=['POST'])
def push():
    pusher.push_status_now()
    return redirect(url_for('control'))


@app.route('/push-malformed', methods=['POST'])
def push_malformed():
    pusher.push_malformed()
    return redirect(url_for('control'))


@app.route('/push-bad-secret', methods=['POST'])
def push_bad_secret():
    pusher.push_bad_secret()
    return redirect(url_for('control'))


@app.route('/log/upload', methods=['POST'])
def log_upload():
    pusher.upload_log(SAMPLE_LOG)
    return redirect(url_for('control'))


@app.route('/scheduler/<action>', methods=['POST'])
def scheduler(action):
    state.set_scheduler(action == 'start')
    return redirect(url_for('control'))


pusher.start_scheduler()
