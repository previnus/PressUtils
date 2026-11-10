"""Runner-local HTTPS receiver for synthetic PressUtils deliveries."""

from hashlib import sha256
import hmac
from http.server import BaseHTTPRequestHandler, HTTPServer
import json
import os
import re
import ssl
import sys
import time


secret = os.environ['WPUS_RECEIVER_KEY'].encode()
key_id = sha256(secret).hexdigest()[:16]
seen = {}
unavailable = set()


class Receiver(BaseHTTPRequestHandler):
    def log_message(self, *_):
        pass

    def do_POST(self):
        delivery = self.headers.get('X-WPUS-Delivery-ID', '')
        timestamp = self.headers.get('X-WPUS-Timestamp', '')
        signature = self.headers.get('X-WPUS-Signature', '')
        length = self.headers.get('Content-Length', '')
        status = 400
        if (self.path in ('/accept', '/recover') and re.fullmatch(r'[0-9a-f-]{36}', delivery)
                and re.fullmatch(r'[0-9]{10}', timestamp) and re.fullmatch(r'[0-9]{1,4}', length)
                and int(length) <= 4096 and re.fullmatch(r'v1=[0-9a-f]{64}', signature)):
            body = self.rfile.read(int(length))
            signed = f'{timestamp}\n{delivery}\n'.encode() + body
            expected = 'v1=' + hmac.new(secret, signed, sha256).hexdigest()
            if (self.headers.get('X-WPUS-Key-ID') == key_id and abs(time.time() - int(timestamp)) <= 300
                    and hmac.compare_digest(expected, signature)):
                try:
                    payload = json.loads(body)
                except (UnicodeError, ValueError):
                    payload = None
                if isinstance(payload, dict) and payload.get('event_type') == 'wpus.test' and payload.get('schema_version') == 1:
                    digest = sha256(body).digest()
                    if delivery in seen:
                        status = 208 if seen[delivery] == digest else 409
                    elif self.path == '/recover' and delivery not in unavailable:
                        unavailable.add(delivery)
                        status = 503
                    else:
                        seen[delivery] = digest
                        status = 204
            else:
                status = 401
        self.send_response(status)
        if status == 503:
            self.send_header('Retry-After', '90')
        self.end_headers()


server = HTTPServer(('93.184.215.20', 443), Receiver)
tls = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
tls.load_cert_chain(sys.argv[1], sys.argv[2])
server.socket = tls.wrap_socket(server.socket, server_side=True)
server.serve_forever()
