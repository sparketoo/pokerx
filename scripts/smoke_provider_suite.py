"""Run ProtoProvider against an actual local protocol server in success/failure modes."""
from smoke_common import *
import signal
results=[]
for i,(mode,expected) in enumerate([('success','success'),('duplicate','success'),('reject','provider_rejected'),('malformed','provider_rejected'),('disconnect','provider_unavailable'),('timeout','solve_timeout'),('wrong_game','solve_timeout')]):
    env=dict(os.environ,FIXTURE_PORT=str(18182+i),FIXTURE_MODE=mode,XDEBUG_MODE='off')
    log=open(ROOT/'runtime/proto-fixture-suite.log','a')
    server=subprocess.Popen(['php','test/Fixtures/proto_server.php'],cwd=ROOT,env=env,stdout=log,stderr=log,start_new_session=True)
    try:
        time.sleep(.4)
        test=subprocess.run(['php','scripts/smoke_provider.php','--fixture','--expect='+expected],cwd=ROOT,env=env,capture_output=True,text=True,timeout=25)
        result=json.loads(test.stdout.strip().splitlines()[-1]);assert test.returncode==0,(mode,result,test.stderr)
        results.append({'scenario':mode,'result':'passed'})
    finally:
        os.killpg(server.pid,signal.SIGTERM);server.wait(timeout=5);log.close()
print(json.dumps({'result':'passed','scenarios':results}))
