#!/usr/bin/env python3
"""Refuse a release while its repository has open Dependabot alerts (Elegia-io/cadence issue 2488).

A verbatim copy of Elegia-io/cadence's scripts/check_dependabot_alerts.py but for this
docstring: change both. This repository uses only the GH_TOKEN path (release.yml).

    GH_TOKEN=<token> python3 -I check-dependabot-alerts.py --repo OWNER/NAME
    python3 -I check-dependabot-alerts.py --repo OWNER/NAME --app-credentials

In a workflow the token is GITHUB_TOKEN with `vulnerability-alerts: read`, from the
environment, never argv. On the host (deploy/host/panel-next.sh, through systemd-run)
`--app-credentials` mints a one-hour installation token of an Elegia-io GitHub App with
Dependabot alerts read only, from three systemd credentials in $CREDENTIALS_DIRECTORY
(APP_CREDENTIALS below): it signs a ten-minute RS256 JWT with `openssl dgst -sign`,
given the key's path, never its bytes, and asks for the token scoped to the one
repository and `vulnerability_alerts: read`. No long-lived token sits on the host.

Exit codes, each reachable only on purpose:
  0  every page fetched and parsed, and the open list is explicitly empty;
  3  open alerts: each printed as `#<number> <severity> <package>`, nothing else;
  2  nothing measured (no token, an HTTP error, alerts disabled, an unparsable
     reply, pagination that cannot complete), the branch named. Also argparse's
     usage error and any uncaught exception (caught below), never a pass.
Callers refuse every code but 0. Python's default error exit, 1, is not used.

BOUNDARY: a pass needs a 200 whose body parses as a JSON list on every page, and the
last page carrying no `next` link. Advisory text is third-party and is never printed:
only the alert number (an int), the severity (one of four words) and a package name
matching a conservative pattern.
"""
from __future__ import annotations

import argparse
import json
import os
import base64
import re
import subprocess
import sys
import time
import urllib.error
import urllib.request

API = "https://api.github.com"
MAX_PAGES = 50  # 5,000 open alerts; past that, refuse rather than guess
SEVERITIES = {"low", "medium", "high", "critical"}
PACKAGE_RE = re.compile(r"[A-Za-z0-9@/._+:-]{1,214}")
REPO_RE = re.compile(r"[A-Za-z0-9][A-Za-z0-9-]*/(?!\.\.?$)[A-Za-z0-9_.-]+")
NEXT_RE = re.compile(r'<([^<>]+)>;\s*rel="next"')
OPEN, UNMEASURED = 3, 2
APP_CREDENTIALS = ("cadence-panel-alerts-app-id", "cadence-panel-alerts-installation-id",
                   "cadence-panel-alerts-app-key")
DIGITS = re.compile(r"[0-9]{1,20}")


class Unmeasured(Exception):
    def __init__(self, branch: str, detail: str):
        super().__init__(detail)
        self.branch = branch


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    """A redirect would carry the token to wherever it points: refuse it instead."""
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise Unmeasured("redirect", f"HTTP {code}: the API redirected; refusing to follow")


_OPENER = urllib.request.build_opener(_NoRedirect)


def fetch(url: str, token: str) -> tuple[int, dict, bytes]:
    req = urllib.request.Request(url, headers={
        "Accept": "application/vnd.github+json", "Authorization": f"Bearer {token}",
        "X-GitHub-Api-Version": "2022-11-28", "User-Agent": "cadence-release-gate"})
    try:
        with _OPENER.open(req, timeout=30) as r:
            return r.status, dict(r.headers), r.read()
    except urllib.error.HTTPError as e:
        return e.code, dict(e.headers or {}), e.read() or b""
    except (urllib.error.URLError, OSError) as e:
        raise Unmeasured("unreachable", f"cannot reach {API}: {type(e).__name__}") from None


def post(url: str, jwt: str, body: bytes) -> tuple[int, bytes]:
    req = urllib.request.Request(url, data=body, method="POST", headers={
        "Accept": "application/vnd.github+json", "Authorization": f"Bearer {jwt}",
        "X-GitHub-Api-Version": "2022-11-28", "User-Agent": "cadence-release-gate"})
    try:
        with _OPENER.open(req, timeout=30) as r:
            return r.status, r.read()
    except urllib.error.HTTPError as e:
        return e.code, e.read() or b""
    except (urllib.error.URLError, OSError) as e:
        raise Unmeasured("unreachable", f"cannot reach {API}: {type(e).__name__}") from None


def _b64(raw: bytes) -> str:
    return base64.urlsafe_b64encode(raw).rstrip(b"=").decode()


def sign(key_path: str, data: bytes) -> bytes:
    done = subprocess.run(["openssl", "dgst", "-sha256", "-sign", key_path], input=data,
                          capture_output=True, timeout=30)
    if done.returncode != 0 or not done.stdout:
        raise Unmeasured("app-token", "openssl could not sign with the app key")
    return done.stdout


def app_token(repo: str, creds_dir: str, post=post, sign=sign, now=time.time) -> str:
    """A one-hour installation token, scoped to `repo` and Dependabot alerts read."""
    if not creds_dir:
        raise Unmeasured("no-app-credentials", "CREDENTIALS_DIRECTORY is unset: run through systemd-run with LoadCredential=")
    paths = [os.path.join(creds_dir, n) for n in APP_CREDENTIALS]
    for path in paths:
        if not os.path.isfile(path):
            raise Unmeasured("no-app-credentials", f"credential {os.path.basename(path)} is missing")
    app_id, inst = (open(x).read().strip() for x in paths[:2])
    if not (DIGITS.fullmatch(app_id) and DIGITS.fullmatch(inst)):
        raise Unmeasured("no-app-credentials", "the app id and installation id must be digits")
    t = int(now())
    head = _b64(json.dumps({"alg": "RS256", "typ": "JWT"}).encode())
    claims = _b64(json.dumps({"iat": t - 60, "exp": t + 540, "iss": app_id}).encode())
    signing = f"{head}.{claims}".encode()
    jwt = f"{head}.{claims}.{_b64(sign(paths[2], signing))}"
    body = json.dumps({"repositories": [repo.split("/", 1)[1]],
                       "permissions": {"vulnerability_alerts": "read"}}).encode()
    status, raw = post(f"{API}/app/installations/{inst}/access_tokens", jwt, body)
    if status != 201:
        raise Unmeasured("app-token", f"HTTP {status}: the app could not mint an installation token")
    try:
        token = json.loads(raw)["token"]
    except (ValueError, KeyError, TypeError):
        raise Unmeasured("app-token", "the installation token reply is unparsable") from None
    if not isinstance(token, str) or not token:
        raise Unmeasured("app-token", "the installation token reply carries no token")
    return token


def _http_branch(status: int, body: bytes) -> Unmeasured:
    try:
        message = str(json.loads(body).get("message", ""))
    except (ValueError, AttributeError):
        message = ""
    if status == 403 and "disabled" in message.lower():
        return Unmeasured("alerts-disabled", "Dependabot alerts are disabled for this repository (HTTP 403)")
    if status in (403, 429) and "rate limit" in message.lower():
        return Unmeasured("rate-limited", f"HTTP {status}: rate limited; retry later")
    named = {401: "unauthorized", 403: "forbidden", 404: "not-found"}
    return Unmeasured(named.get(status, "http-error"),
                      f"HTTP {status}: the token cannot read this repository's Dependabot alerts")


def open_alerts(repo: str, token: str, get=fetch) -> list[dict]:
    """Every open alert on every page, or Unmeasured. Never an empty list by default."""
    if not REPO_RE.fullmatch(repo):
        raise Unmeasured("bad-repo", "--repo must be OWNER/NAME")
    if not token:
        raise Unmeasured("no-token", "GH_TOKEN is empty or unset")
    first = f"{API}/repos/{repo}/dependabot/alerts?state=open&per_page=100"
    prefix = f"{API}/repos/{repo}/dependabot/alerts?"
    url, seen, alerts = first, set(), []
    while url:
        if url in seen or len(seen) >= MAX_PAGES:
            raise Unmeasured("pagination", f"pagination did not end within {MAX_PAGES} pages")
        if not url.startswith(prefix):
            raise Unmeasured("pagination", "a next link points outside this repository's alerts")
        seen.add(url)
        status, headers, body = get(url, token)
        if status != 200:
            raise _http_branch(status, body)
        try:
            page = json.loads(body)
        except ValueError:
            raise Unmeasured("unparsable", "a page is not JSON") from None
        if not isinstance(page, list):
            raise Unmeasured("unparsable", "a page is not a JSON list")
        for a in page:
            if not (isinstance(a, dict) and type(a.get("number")) is int and a.get("state") == "open"):
                raise Unmeasured("unparsable", "an alert has no integer number or is not open")
        alerts += page
        link = next((v for k, v in headers.items() if k.lower() == "link"), "")
        m = NEXT_RE.search(link)
        url = m.group(1) if m else None
    return alerts


def line(a: dict) -> str:
    adv = a.get("security_advisory") if isinstance(a.get("security_advisory"), dict) else {}
    sev = adv.get("severity")
    sev = sev if sev in SEVERITIES else "unknown-severity"
    dep = a.get("dependency") if isinstance(a.get("dependency"), dict) else {}
    pkg = dep.get("package") if isinstance(dep.get("package"), dict) else {}
    name = pkg.get("name")
    name = name if isinstance(name, str) and PACKAGE_RE.fullmatch(name) else "unprintable-package"
    return f"#{a['number']} {sev} {name}"


def main(argv: list[str] | None = None, get=fetch, mint=app_token) -> int:
    p = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    p.add_argument("--repo", required=True)
    p.add_argument("--app-credentials", action="store_true",
                   help="mint a GitHub App installation token from $CREDENTIALS_DIRECTORY")
    args = p.parse_args(argv)
    try:
        if args.app_credentials:
            if not REPO_RE.fullmatch(args.repo):
                raise Unmeasured("bad-repo", "--repo must be OWNER/NAME")
            token = mint(args.repo, os.environ.get("CREDENTIALS_DIRECTORY", ""))
        else:
            token = os.environ.get("GH_TOKEN", "")
        alerts = open_alerts(args.repo, token, get)
    except Unmeasured as e:
        print(f"[branch: {e.branch}] {args.repo}: {e}; measured nothing, refusing", file=sys.stderr)
        return UNMEASURED
    except Exception as e:  # a breakage is never a verdict
        print(f"[branch: breakage] {args.repo}: {type(e).__name__}; refusing", file=sys.stderr)
        return UNMEASURED
    if alerts:
        print(f"[branch: open-alerts] {args.repo} has {len(alerts)} open Dependabot alert(s); refusing the release:",
              file=sys.stderr)
        for a in alerts:
            print(line(a), file=sys.stderr)
        return OPEN
    print(f"{args.repo}: 0 open Dependabot alerts (every page read)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
