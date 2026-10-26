#!/usr/local/bin/python3
"""
 * Copyright (C) 2026 Blip9575
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 *    this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 * INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 * AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 * OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.

 scheduled speedtests against the servers that measured the line best:

   scheduled.py run       one scheduled speedtest (the cron task)
   scheduled.py servers   the ranking, for the page

 Servers are ranked by their own results of the last RANK_DAYS days: each result is
 scored against the fastest download of the same day, so a change of the line moves
 all servers alike, and a server's score is the median of its scores. The first
 interval of a night fetches the nearest servers ("Get server list"). Each interval
 runs "multiple" tests, which take turns on the best ranked servers on that list (the
 first test starts with the best, the second with the next one...) and fall back to
 the other candidates and to the server speedtest picks itself. Once a night (not on
 the first interval) a server of the list without enough results gets a trial, as an
 extra test, so the ranking can learn. Every test is saved in the results (csv) and
 logged to syslog; the shaper averages the results of an hour.
"""
import csv
import json
import os
import statistics
import subprocess
import sys
import syslog
import time
import xml.etree.ElementTree as ET
from datetime import datetime

SCRIPT = '/usr/local/opnsense/scripts/OPNsense/speedtest/opn_speedtest.py'
CSVFILE = '/usr/local/opnsense/scripts/OPNsense/speedtest/speedtest.csv'
STATEFILE = '/usr/local/opnsense/scripts/OPNsense/speedtest/scheduled.json'
CONFIG = '/conf/config.xml'

RANK_DAYS = 30      # results used for the ranking
RANK_RESULTS = 2    # results a server needs to be ranked
CANDIDATES = 3      # ranked servers tried before falling back
PAUSE = 10          # seconds between the tests of an interval


def speedtest(*args):
    """ run opn_speedtest.py (a server id, '0' for speedtest's own pick, or 'list'); it always answers in json """
    proc = subprocess.run(['/usr/local/bin/python3', SCRIPT] + list(args),
                          stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    try:
        return json.loads(proc.stdout.decode('utf-8'))
    except ValueError:
        return {'error': 'no json from opn_speedtest.py: ' + proc.stderr.decode('utf-8', 'replace').strip()[-200:]}


def multiple():
    """ speedtests at each interval, as set on the page (1 to 5) """
    try:
        node = ET.parse(CONFIG).getroot().find('OPNsense/speedtest/shaper/multiple')
        return max(1, min(5, int(node.text)))
    except (OSError, ET.ParseError, AttributeError, TypeError, ValueError):
        return 3


def log(message):
    syslog.syslog(syslog.LOG_NOTICE, message)


def load_state():
    try:
        with open(STATEFILE, 'r', encoding='utf-8') as f:
            state = json.load(f)
        if isinstance(state, dict):
            return state
    except (OSError, ValueError):
        pass
    return {}


def save_state(state):
    tmp = STATEFILE + '.tmp'
    with open(tmp, 'w', encoding='utf-8') as f:
        json.dump(state, f)
    os.replace(tmp, STATEFILE)


def ranking(now=None):
    """
    servers of the last RANK_DAYS days: id => {name, results, score}; score is the
    median of download / fastest download of the same day, days with one result
    (nothing to compare with) count for the number of results only
    """
    now = now or time.time()
    days = {}
    try:
        with open(CSVFILE, 'r', encoding='utf-8') as f:
            data = csv.reader(f, dialect='excel')
            next(data, None)
            for row in data:
                try:
                    stamp, download = float(row[0]), float(row[5])
                except (IndexError, ValueError):
                    continue
                if stamp < now - RANK_DAYS * 86400 or download <= 0:
                    continue
                day = datetime.fromtimestamp(stamp).strftime('%Y-%m-%d')
                days.setdefault(day, []).append((str(row[2]), row[3], download))
    except OSError:
        return {}
    servers = {}
    for results in days.values():
        fastest = max(r[2] for r in results)
        for serverid, name, download in results:
            server = servers.setdefault(serverid, {'name': name, 'results': 0, 'scores': []})
            server['name'] = name
            server['results'] += 1
            if len(results) > 1:
                server['scores'].append(download / fastest)
    for server in servers.values():
        scores = server.pop('scores')
        server['score'] = round(statistics.median(scores), 3) if scores else None
    return servers


def ranked(servers):
    """ ids of the servers with enough results to be ranked, best first """
    ids = [i for i, s in servers.items() if s['results'] >= RANK_RESULTS and s['score'] is not None]
    return sorted(ids, key=lambda i: (-servers[i]['score'], -servers[i]['results']))


def test(order, label):
    """ one speedtest: the servers of order in turn ('0': speedtest's own pick) until one gives a result """
    messages = []
    for serverid in order:
        result = speedtest(serverid)
        what = 'server ' + serverid if serverid != '0' else 'speedtest\'s pick'
        if 'error' in result or 'download' not in result:
            messages.append('%s %s failed: %s' % (label, what, result.get('error', result)))
            log(messages[-1])
            continue
        messages.append('%s %s: %s Mbit/s down, %s Mbit/s up' % (label, what, result['download'], result['upload']))
        log(messages[-1])
        return result, messages
    return None, messages


def run():
    state = load_state()
    night = datetime.now().strftime('%Y-%m-%d')
    first = state.get('night') != night
    messages = []
    if first:
        # a new night: fetch the nearest servers and keep the previous list to compare
        found = speedtest('list')
        if isinstance(found, list) and found:
            state['previous'] = state.get('list', [])
            state['list'] = [{'id': str(s['id']), 'name': s['name'] + ', ' + s['location']} for s in found]
            messages.append('server list: ' + ', '.join(s['id'] for s in state['list']))
        else:
            messages.append('server list failed, using the last one: ' +
                            (found.get('error', '') if isinstance(found, dict) else ''))
        log(messages[-1])
        state.update({'night': night, 'tests': 0, 'trial': None})
    state['tests'] = state.get('tests', 0) + 1
    listed = [s['id'] for s in state.get('list', [])]
    previous = [s['id'] for s in state.get('previous', [])]

    servers = ranking()
    best = ranked(servers)
    # a list that could never be fetched does not filter, otherwise only listed servers
    candidates = [i for i in best if not listed or i in listed][:CANDIDATES]

    trial = None
    if not first and not state.get('trial') and listed:
        # unranked servers of the list; the fewest trials first, then new on the list, then nearest
        trials = state.get('trials', {})
        unranked = [i for i in listed if i not in best]
        if unranked:
            trial = min(unranked, key=lambda i: (trials.get(i, 0), i in previous, listed.index(i)))
            trials[trial] = trials.get(trial, 0) + 1
            state['trials'] = trials
            state['trial'] = trial
    save_state(state)

    count = multiple()
    results = []
    error = None
    for n in range(count):
        if n > 0:
            time.sleep(PAUSE)
        # the tests take turns on the candidates: test n starts with candidate n
        k = n % len(candidates) if candidates else 0
        # speedtest's own pick last, twice: it often picks a server that refuses the test
        order = candidates[k:] + candidates[:k] + ['0', '0']
        result, lines = test(order, 'test %d/%d' % (n + 1, count))
        messages += lines
        if result is None:
            error = lines[-1]
        else:
            results.append(result)
    if trial:
        # an extra test, on the trial server only
        time.sleep(PAUSE)
        result, lines = test([trial], 'trial')
        messages += lines
        if result is not None:
            results.append(result)
    out = {'tests': count + (1 if trial else 0), 'results': len(results), 'scheduled': messages}
    if results:
        out['download'] = round(statistics.mean(r['download'] for r in results), 2)
        out['upload'] = round(statistics.mean(r['upload'] for r in results), 2)
    else:
        out['error'] = error or 'no server to test'
    print(json.dumps(out))


def servers_out():
    state = load_state()
    listed = [s['id'] for s in state.get('list', [])]
    servers = ranking()
    best = ranked(servers)
    out = []
    for i in best:
        out.append({'id': i, 'name': servers[i]['name'], 'score': servers[i]['score'],
                    'results': servers[i]['results'], 'listed': not listed or i in listed})
    print(json.dumps({'servers': out, 'candidates': [s['id'] for s in out if s['listed']][:CANDIDATES],
                      'night': state.get('night', ''), 'trial': state.get('trial')}))


if __name__ == '__main__':
    syslog.openlog('speedtest', 0, syslog.LOG_USER)
    try:
        if len(sys.argv) > 1 and sys.argv[1] == 'servers':
            servers_out()
        else:
            run()
    except Exception as e:
        # never exit non-zero, configd only logs a traceback; return the reason as json
        print(json.dumps({'error': 'Scheduled speedtest failed: ' + type(e).__name__ + ': ' + str(e)}))
