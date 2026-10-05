import subprocess, time, statistics, http.client, sys
STACK = sys.argv[1]
def wp(*a): subprocess.run(["docker","compose","-f",STACK+"/docker-compose.yml","exec","-T","cli","wp",*a], capture_output=True)
def run(label, ua, n=300, path="/how-to-brew-pour-over-coffee/"):
    c = http.client.HTTPConnection("localhost", 8095)
    times = []
    for i in range(n):
        t = time.perf_counter(); c.request("GET", path, headers={"User-Agent": ua, "X-Bench": label}); r = c.getresponse(); r.read(); times.append((time.perf_counter()-t)*1000)
    times.sort()
    return statistics.median(times), times[int(n*0.95)]
HUMAN = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36"
BOT = "Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.4; +https://openai.com/gptbot"
subprocess.run(["docker","compose","-f",STACK+"/docker-compose.yml","exec","-T","wp","sh","-c","rm -f /tmp/bench-q.log"])
res = {}
for rnd in range(3):  # interleave to cancel drift
    wp("plugin","deactivate","aibotdetection"); run("warm", HUMAN, 20)
    res.setdefault("off-human", []).append(run("off-human", HUMAN))
    wp("plugin","activate","aibotdetection"); run("warm", HUMAN, 20)
    res.setdefault("on-human", []).append(run("on-human", HUMAN))
    res.setdefault("on-gptbot", []).append(run("on-gptbot", BOT))
log = subprocess.run(["docker","compose","-f",STACK+"/docker-compose.yml","exec","-T","wp","cat","/tmp/bench-q.log"], capture_output=True, text=True).stdout.split("\n")
q = {}
for l in log:
    p = l.split()
    if len(p) == 3 and p[0] != "warm":
        q.setdefault(p[0], {"q": [], "ms": []}); q[p[0]]["q"].append(int(p[1])); q[p[0]]["ms"].append(float(p[2]))
for k in ("off-human", "on-human", "on-gptbot"):
    med = statistics.median([m for m, _ in res[k]]); p95 = statistics.median([p for _, p in res[k]])
    print(f"{k:10s} wall p50 {med:6.2f} ms  p95 {p95:6.2f} ms | PHP p50 {statistics.median(q[k]['ms']):6.2f} ms | DB queries per request: {sorted(set(q[k]['q']))}")
