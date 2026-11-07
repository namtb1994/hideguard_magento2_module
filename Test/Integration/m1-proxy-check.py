#!/usr/bin/env python3
"""Run against the isolated fixture/proxy described in HIGH_TRAFFIC_M1_EVIDENCE.md."""
import http.client
import json
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[6]
DIRECTORY = ROOT / 'var/hg_m1_293a9f51cd87'
CONTAINER_DIRECTORY = '/var/www/html/var/hg_m1_293a9f51cd87'

def driver(action, *arguments):
    return subprocess.check_output(['docker', 'compose', 'exec', '-T', '-e', 'HG_M1_DIR=' + CONTAINER_DIRECTORY,
        'php', 'php', 'app/code/Hachitechs/Hideguard/Test/Integration/m1-proxy-fixture.php', action, *arguments],
        cwd=ROOT, text=True).strip()

def request(path, browser='A', host='shop.example', extra=None):
    connection = http.client.HTTPConnection('127.0.0.1', 18081, timeout=5)
    headers = {'Host': host, 'Cookie': 'browser=' + browser}
    headers.update(extra or {})
    connection.request('GET', path, headers=headers)
    response = connection.getresponse()
    result = (response.status, dict((k.lower(), v) for k, v in response.getheaders()), response.read().decode())
    connection.close()
    return result

def assert_pass(path, host='shop.example', extra=None):
    a, b = request(path, host=host, extra=extra), request(path, host=host, extra=extra)
    assert a[1]['x-backend-count'] != b[1]['x-backend-count'], (path, a, b)
    assert a[1]['x-cache'] == b[1]['x-cache'] == 'MISS', (path, a, b)

original = (DIRECTORY / 'id').read_text()
# A fresh activation purges fixtures warmed by manual smoke checks.
driver('activate', original)
a = request('/en/about-us')
b = request('/en/about-us', 'B')
assert a[1]['x-cache'] == 'MISS' and b[1]['x-cache'] == 'HIT', (a, b)
assert a[1]['x-backend-count'] == b[1]['x-backend-count']
assert 'verificationId' not in b[2] and 'PROTECTED' not in b[2]
for path, host in [('/en/diamond.html', 'shop.example'), ('/en/catalog/Product/View/id/1', 'shop.example'),
    ('/en/secret/report', 'shop.example'), ('/en/diamond.html?query=one', 'secure.example'),
    ('/fr/about-us', 'other.example')]:
    allowed = request(path, 'A', host)
    blocked = request(path, 'B', host)
    assert allowed[0] == 200 and 'PROTECTED:A verificationId=' in allowed[2], allowed
    assert blocked[0] == 401 and blocked[2] == 'BLOCKED:B', blocked
    assert allowed[1]['x-cache'] == blocked[1]['x-cache'] == 'MISS'
    assert 'private' in allowed[1]['cache-control'] and 'no-store' in blocked[1]['cache-control']
    assert allowed[2].split('verificationId=')[1] not in blocked[2]
for path, host, extra in [('/en/%64iamond.html', 'shop.example', None),
    ('/en//about-us', 'shop.example', None), ('/en/x/../about-us', 'shop.example', None),
    ('/en/index.php', 'shop.example', None), ('/en/\\about-us', 'shop.example', None),
    ('/en/about-us;___store=fr', 'shop.example', None),
    ('/en/about-us', 'shop.example', {'Cookie': 'browser=A; store = fr'}),
    ('/en/index.php/about-us', 'shop.example', None), ('/en/about-us?___store=fr', 'shop.example', None),
    ('/EN/about-us', 'shop.example', None), ('/elsewhere', 'shop.example', None),
    ('/en/about-us', 'unknown.example', None), ('/en/about-us', 'shop.example', {'Store': 'fr'}),
    ('/en/about-us', 'shop.example', {'X-Original-URL': '/en/diamond.html'}),
    ('/en/antidetectbrowser/gate/index', 'shop.example', None)]:
    assert_pass(path, host, extra)
print('PASS public MISS→HIT: one backend request; protected A/B, token isolation, hosts/base paths and ambiguous requests stay PASS.')
a = request('/en/offer.html'); b = request('/en/offer.html')
assert b[1]['x-cache'] == 'HIT' and b[2] == 'PUBLIC:/offer.html'
driver('mutate')
a = request('/en/offer.html', 'A'); b = request('/en/offer.html', 'B')
assert 'PROTECTED:A' in a[2] and b[0] == 401 and b[2] == 'BLOCKED:B', (a, b)
assert a[1]['x-cache'] == b[1]['x-cache'] == 'MISS'
assert_pass('/en/about-us')
asset_a = request('/static/fixture.css'); asset_b = request('/static/fixture.css')
assert asset_b[1]['x-cache'] == 'HIT', (asset_a, asset_b)
failed = subprocess.run(['docker', 'compose', 'exec', '-T', '-e', 'HG_M1_DIR=' + CONTAINER_DIRECTORY,
    'php', 'php', 'app/code/Hachitechs/Hideguard/Test/Integration/m1-proxy-fixture.php', 'activate', original],
    cwd=ROOT, capture_output=True)
assert failed.returncode != 0 and b'stale' in failed.stdout + failed.stderr
new = driver('export')
assert new != original
# New descriptor without reloading proxy must not pass the active-VCL acknowledgement.
failed = subprocess.run(['docker', 'compose', 'exec', '-T', '-e', 'HG_M1_DIR=' + CONTAINER_DIRECTORY,
    'php', 'php', 'app/code/Hachitechs/Hideguard/Test/Integration/m1-proxy-fixture.php', 'activate', new],
    cwd=ROOT, capture_output=True)
assert failed.returncode != 0 and not list(DIRECTORY.glob('ready-*'))
assert_pass('/en/about-us')
print('PASS rewrite transition before republish: old public object is never served; stale/wrong active VCL cannot activate.')
print(json.dumps({'old': original, 'new': new}))
