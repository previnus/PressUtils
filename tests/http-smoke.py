"""Exercise wp-admin and REST on a disposable local WordPress install."""

import getpass
import http.cookiejar
import json
import os
import re
import urllib.error
import urllib.parse
import urllib.request
import uuid
from html.parser import HTMLParser


BASE = os.getenv("WPUS_SMOKE_URL", "http://127.0.0.1:38142").rstrip("/")
ADMIN = "/wp-admin/admin.php?page=wpus"
PASSWORD = os.getenv("WPUS_SMOKE_PASSWORD") or getpass.getpass("Local WordPress password: ")


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, response, code, message, headers, new_url):
        return None


class Forms(HTMLParser):
    def __init__(self, html):
        super().__init__()
        self.forms = []
        self.current = None
        self.feed(html)

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == "form":
            self.current = {}
        if tag == "input" and self.current is not None and attrs.get("type") == "hidden":
            self.current[attrs.get("name")] = attrs.get("value", "")

    def handle_endtag(self, tag):
        if tag == "form" and self.current is not None:
            self.forms.append(self.current)
            self.current = None

    def command(self, command):
        return next(form.copy() for form in self.forms if form.get("command") == command)


def client(cookies=None, follow=True):
    handlers = []
    if cookies is not None:
        handlers.append(urllib.request.HTTPCookieProcessor(cookies))
    if not follow:
        handlers.append(NoRedirect())
    return urllib.request.build_opener(*handlers)


def request(opener, path, fields=None, headers=None, method=None, payload=None):
    if fields is not None:
        payload = urllib.parse.urlencode(fields).encode()
    req = urllib.request.Request(BASE + path, data=payload, headers=headers or {}, method=method)
    try:
        response = opener.open(req, timeout=30)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.status, response.headers, response.read().decode(errors="replace")


def check(condition, description):
    if not condition:
        raise AssertionError(description)
    print("PASS " + description)


def rest(path):
    return "/?rest_route=/wpus/v1/" + path


anonymous = client(follow=False)
status, headers, _ = request(anonymous, rest("health"))
check(status == 401 and "no-store" in headers.get("Cache-Control", ""), "Unauthenticated health is private")

cookies = http.cookiejar.CookieJar()
session = client(cookies)
request(session, "/wp-login.php")
status, _, overview = request(
    session,
    "/wp-login.php",
    fields={"log": os.getenv("WPUS_SMOKE_USER", "wpusadmin"), "pwd": PASSWORD, "testcookie": "1", "redirect_to": BASE + ADMIN},
)
check(status == 200 and "Utility Suite modules" in overview, "Authenticated WordPress dashboard renders")
direct = client(cookies, follow=False)
panels = {}
for module in ["security", "performance", "crm", "admin_tools", "woocommerce", "monitoring"]:
    status, _, panels[module] = request(session, ADMIN + "&tab=" + module)
    check(status == 200 and "Utility Suite modules" in panels[module], f"Admin panel opens for {module}")

status, _, _ = request(
    direct, "/wp-admin/admin-post.php",
    fields={"action": "wpus_action", "command": "note_save", "tab": "admin_tools", "title": "Forged", "body": "Missing nonce"},
)
check(status == 403, "Mutation without a nonce is rejected")
form = Forms(panels["admin_tools"]).command("note_save")
marker = "smoke-" + uuid.uuid4().hex[:8]
form.update(title=marker, body=f'<script id="{marker}">bad()</script>Plain text note', post_id="")
status, headers, _ = request(direct, "/wp-admin/admin-post.php", fields=form)
check(status == 303 and "wpus_status=saved" in headers["Location"], "Valid note form redirects with saved status")
status, _, content = request(session, ADMIN + "&tab=admin_tools")
check(marker in content and f'<script id="{marker}">' not in content, "Saved note cannot inject markup")

form = Forms(panels["security"]).command("audit_export")
status, headers, content = request(direct, "/wp-admin/admin-post.php", fields=form)
rows = [json.loads(line) for line in content.splitlines()]
check(status == 200 and headers.get_content_type() == "application/x-ndjson" and bool(rows), "Audit export streams JSON Lines")

nonce = re.search(r"""createNonceMiddleware\(\s*["']([0-9a-f]{10})["']\s*\)""", overview).group(1)
auth = {"X-WP-Nonce": nonce}
status, headers, health = request(session, rest("health"), headers=auth)
check(status == 200 and json.loads(health)["checks"]["schema"] == "ok" and "no-store" in headers["Cache-Control"],
      "Browser-authenticated health returns redacted readiness")
status, headers, config = request(session, rest("settings/security"), headers=auth)
check(status == 200 and bool(headers.get("ETag")), "Settings response provides a revision ETag")
etag = headers["ETag"]
old = json.loads(config)["config"]["retention"]
patch_headers = auth | {"Content-Type": "application/json", "If-Match": etag}
status, _, _ = request(session, rest("settings/security"), headers=patch_headers, method="PATCH",
                       payload=json.dumps({"retention": 89 if old == 90 else 90}).encode())
check(status == 200, "REST PATCH saves a current revision")
status, _, _ = request(session, rest("settings/security"), headers=patch_headers, method="PATCH",
                       payload=json.dumps({"retention": old}).encode())
check(status == 412, "REST PATCH rejects a stale revision")
status, _, content = request(session, rest("audit/events") + "&from=2026-11-%00", headers=auth)
check(status == 400 and json.loads(content)["code"] == "wpus_invalid", "REST rejects a malformed audit date")
status, _, content = request(session, "/?rest_route=/WPUS/v1/settings/security",
                             headers=auth | {"X-HTTP-Method-Override": "PATCH"})
check(status == 400 and json.loads(content)["code"] == "wpus_method", "Mixed-case REST path still rejects a method override")

form = Forms(panels["monitoring"]).command("redirect_save")
form.update(source_path="/" + marker + "/", target_path="/", status_code="302")
status, headers, _ = request(direct, "/wp-admin/admin-post.php", fields=form)
check(status == 303 and "wpus_status=saved" in headers["Location"], "Redirect form creates a rule")
form = Forms(panels["monitoring"]).command("settings")
form.update(enabled="1", redirects="1", record_404="1")
status, headers, _ = request(direct, "/wp-admin/admin-post.php", fields=form)
check(status == 303 and "wpus_status=saved" in headers["Location"], "Redirect and 404 recording can be enabled")
status, headers, _ = request(anonymous, "/" + marker + "/?tracking=discard")
check(status == 302 and headers["Location"] == BASE + "/", "Front-end redirect drops the original query string")
status, _, _ = request(anonymous, "/" + marker + "-missing/?private=discard")
check(status == 404, "Unmatched request remains a 404")
status, _, content = request(session, rest("monitoring/404s"), headers=auth)
check(status == 200 and bool(json.loads(content)) and marker not in content, "404 endpoint exposes aggregates without raw paths")
