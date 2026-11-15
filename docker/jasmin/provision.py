"""One-shot provisioning of Jasmin through its jCli (telnet) interface.

Creates: group + SMPP user for the supplier, HTTP connector -> panel webhook,
MO route (for SMS received over an SMPP *client* connector), MT interceptor (for SMS the
supplier submits to our SMPP *server*), and optionally an SMPP client connector to a supplier.
Safe to re-run: every object is removed first, errors on removal are ignored.
"""
import os
import re
import socket
import sys
import time

HOST = os.environ.get('JASMIN_HOST', 'jasmin')
PORT = 8990
JCLI_USER = 'jcliadmin'
JCLI_PASS = 'jclipwd'

SUP_USER = os.environ['SMPP_SUPPLIER_USER']
SUP_PASS = os.environ['SMPP_SUPPLIER_PASS']
if not (1 <= len(SUP_PASS) <= 8):
    sys.exit('SMPP_SUPPLIER_PASS must be 1-8 characters (SMPP protocol limit)')
WEBHOOK = os.environ['MBC_WEBHOOK_URL']
# optional: Jasmin binds OUT to a supplier's SMPP server and receives deliver_sm
OUT_HOST = os.environ.get('SUPPLIER_CLIENT_HOST', '')
OUT_PORT = os.environ.get('SUPPLIER_CLIENT_PORT', '2775')
OUT_USER = os.environ.get('SUPPLIER_CLIENT_USER', '')
OUT_PASS = os.environ.get('SUPPLIER_CLIENT_PASS', '')

PROMPT = re.compile(rb'(jcli : |> |Username: |Password: )$')


def connect():
    for _ in range(60):
        try:
            return socket.create_connection((HOST, PORT), timeout=5)
        except OSError:
            time.sleep(2)
    sys.exit('jCli not reachable')


s = connect()
s.settimeout(10)
buf = b''


def read_until_prompt():
    global buf
    data = b''
    while True:
        try:
            chunk = s.recv(4096)
        except socket.timeout:
            break
        if not chunk:
            break
        chunk = re.sub(rb'\xff[\xfb-\xfe].', b'', chunk)   # strip telnet negotiation
        data += chunk
        if PROMPT.search(data.rstrip(b'\r\n') + (b' ' if data.endswith(b' ') else b'')) or PROMPT.search(data):
            break
    return data.decode('utf-8', 'replace')


def send(line, show=True):
    s.sendall(line.encode() + b'\r\n')
    out = read_until_prompt()
    if show:
        tail = [l.strip() for l in out.splitlines() if l.strip() and l.strip() not in (line, '>', 'jcli :')]
        if tail:
            print(f'  {line} -> {tail[-1][:120]}')
    return out


def block(start, lines):
    """Run an interactive jCli block: `start`, then each line, then `ok`. Aborts on any error."""
    send(start, show=False)
    for l in lines:
        out = send(l, show=False)
        if 'Error' in out or 'Unknown' in out:
            send('ko', show=False)
            sys.exit(f'jCli rejected "{l.split()[0]} ..." in block "{start}": ' + out.strip().splitlines()[-2][:120])
    out = send('ok')
    if 'Error' in out or 'Unknown' in out or 'Successfully' not in out:
        sys.exit(f'block "{start}" failed: {out.strip()[:200]}')
    return out


read_until_prompt()
send(JCLI_USER, show=False)
send(JCLI_PASS, show=False)
print('logged in to jCli')

# --- clean slate (errors ignored)
for cmd in ('mtinterceptor -r 0', 'morouter -r 0', 'httpccm -r mbc_webhook', 'user -r supplier', 'group -r mbc'):
    send(cmd, show=False)

# --- supplier (inbound SMPP server)
block('group -a', ['gid mbc'])
block('user -a', ['username ' + SUP_USER, 'password ' + SUP_PASS, 'uid supplier', 'gid mbc',
                  'smpps_cred authorization bind yes', 'smpps_cred quota max_bindings 10'])

# --- webhook connector + MO route
block('httpccm -a', ['url ' + WEBHOOK, 'method POST', 'cid mbc_webhook'])
block('morouter -a', ['type DefaultRoute', 'connector http(mbc_webhook)'])

# --- MT interceptor: submit_sm from the supplier -> webhook
block('mtinterceptor -a', ['type DefaultInterceptor', 'script python3(/opt/jasmin-scripts/mt-interceptor.py)'])

# --- optional outbound client connector
if OUT_HOST and OUT_USER:
    send('smppccm -r supplier_out', show=False)
    block('smppccm -a', ['cid supplier_out', 'host ' + OUT_HOST, 'port ' + OUT_PORT,
                         'username ' + OUT_USER, 'password ' + OUT_PASS, 'bind transceiver'])
    send('smppccm -1 supplier_out')

send('persist')
print('provisioning done')
