"""Start the actual Hyperf server, test the public API, and stop only our process group."""
from smoke_common import *
import signal
log_path=ROOT/'runtime/smoke-server.log'
with log_path.open('w') as log:
    process=subprocess.Popen(['php','bin/hyperf.php','start'],cwd=ROOT,env=dict(os.environ,XDEBUG_MODE='off',POKER_PROVIDER='mock'),stdout=log,stderr=log,start_new_session=True)
    try:
        for attempt in range(80):
            if process.poll() is not None: raise RuntimeError('Hyperf failed to start; inspect runtime/smoke-server.log')
            try:
                with urllib.request.urlopen(HTTP+'/health',timeout=1) as r: assert r.status==200
                break
            except Exception: time.sleep(.1)
        else: raise RuntimeError('Hyperf startup timed out')
        for script in ['smoke_all.py','smoke_vip.py','smoke_failures.py','smoke_provider_suite.py','smoke_socket.py']:
            result=subprocess.run(['python3','scripts/'+script],cwd=ROOT,capture_output=True,text=True,timeout=120)
            if result.returncode: raise RuntimeError(script+': '+result.stderr[-1800:]+result.stdout[-1500:])
            print(result.stdout.strip(),flush=True)
    finally:
        if process.poll() is None: os.killpg(process.pid,signal.SIGTERM)
        try: process.wait(timeout=15)
        except subprocess.TimeoutExpired:
            os.killpg(process.pid,signal.SIGKILL);process.wait()
