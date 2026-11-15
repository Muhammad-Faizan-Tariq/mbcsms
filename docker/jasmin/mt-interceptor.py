# Jasmin MT interceptor: runs for every submit_sm a supplier sends to our SMPP server.
# Forwards the SMS to the panel webhook and answers the supplier (smpp_status 0 = ESME_ROK,
# routing is bypassed, so no MT route / downstream connector is needed).
# Non-zero smpp_status makes the supplier retry later.
import json
import os
import uuid
import urllib.request

try:
    params = routable.pdu.params
    raw = params.get('short_message') or b''
    if isinstance(raw, bytes):
        coding = params.get('data_coding')
        coding = coding if isinstance(coding, int) else getattr(coding, 'value', 0)
        try:
            text = raw.decode('utf-16-be' if coding == 8 else 'utf-8')
        except Exception:
            text = raw.decode('latin-1', 'replace')
    else:
        text = str(raw)

    def addr(v):
        return v.decode('latin-1') if isinstance(v, bytes) else str(v or '')

    payload = json.dumps({
        'message_id': str(uuid.uuid4()),
        'source_addr': addr(params.get('source_addr')),
        'destination_addr': addr(params.get('destination_addr')),
        'short_message': text,
        'connector_id': 'smpp-server',
    }).encode()

    req = urllib.request.Request(
        os.environ['MBC_WEBHOOK_URL'], data=payload,
        headers={'Content-Type': 'application/json'}, method='POST')
    with urllib.request.urlopen(req, timeout=10) as resp:
        smpp_status = 0 if resp.status == 200 else 8
except Exception:
    smpp_status = 8   # ESME_RSYSERR -> supplier will retry
