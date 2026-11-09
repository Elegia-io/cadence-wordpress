#!/usr/bin/env python3
"""What `check-dependabot-alerts.py` decides for `.github/workflows/release.yml`.

No release with open Dependabot alerts (Elegia-io/cadence issue 2488). The fetcher
is injected, so nothing here reaches the network.
"""
import contextlib
import importlib.util
import io
import json
import os
import sys
import unittest
from pathlib import Path
from unittest import mock

REPO = Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location("dependabot_alerts", REPO / "check-dependabot-alerts.py")
G = importlib.util.module_from_spec(spec)
spec.loader.exec_module(G)

NAME = "Elegia-io/cadence-wordpress"
FIRST = f"https://api.github.com/repos/{NAME}/dependabot/alerts?state=open&per_page=100"


def alert(n, sev="high", pkg="guzzlehttp/guzzle", state="open"):
    return {"number": n, "state": state, "security_advisory": {"severity": sev,
            "summary": "ADVISORY TEXT"}, "dependency": {"package": {"name": pkg}}}


def pages(*replies):
    calls, it = [], iter(replies)

    def get(url, token):
        calls.append(url)
        status, body, nxt = next(it)
        headers = {"Link": f'<{nxt}>; rel="next"'} if nxt else {}
        return status, headers, body if isinstance(body, bytes) else json.dumps(body).encode()
    return get, calls


def run(get, token="t", repo=NAME):
    out = io.StringIO()
    with mock.patch.dict(os.environ, {"GH_TOKEN": token}), \
            contextlib.redirect_stdout(out), contextlib.redirect_stderr(out):
        rc = G.main(["--repo", repo], get=get)
    return rc, out.getvalue()


class Gate(unittest.TestCase):
    def test_pass_needs_an_explicit_empty_list_from_a_200(self):
        get, calls = pages((200, [], None))
        rc, out = run(get)
        self.assertEqual((rc, calls), (0, [FIRST]))
        self.assertIn("0 open", out)

    def test_no_token_measures_nothing(self):
        get, calls = pages()
        rc, out = run(get, token="")
        self.assertEqual((rc, calls), (2, []))
        self.assertIn("[branch: no-token]", out)

    def test_an_api_error_is_never_zero_alerts(self):
        for status, body, branch in [
                (403, {"message": "Dependabot alerts are disabled for this repository."}, "alerts-disabled"),
                (403, {"message": "Resource not accessible by integration"}, "forbidden"),
                (404, {"message": "Not Found"}, "not-found"),
                (401, {"message": "Bad credentials"}, "unauthorized"),
                (403, {"message": "API rate limit exceeded"}, "rate-limited"),
                (500, b"", "http-error")]:
            rc, out = run(pages((status, body, None))[0])
            self.assertEqual(rc, 2, branch)
            self.assertIn(f"[branch: {branch}]", out)
            self.assertNotIn("0 open", out)

    def test_an_unparsable_reply_refuses(self):
        for body in (b"<html>", {"alerts": []}, [{"state": "open"}],
                     [{"number": "1", "state": "open"}], [alert(1, state="fixed")]):
            rc, out = run(pages((200, body, None))[0])
            self.assertEqual(rc, 2, body)
            self.assertIn("[branch: unparsable]", out)

    def test_a_breakage_is_never_a_verdict(self):
        def get(url, token):
            raise RuntimeError("boom")
        rc, out = run(get)
        self.assertEqual(rc, 2)
        self.assertIn("[branch: breakage]", out)

    def test_open_alerts_refuse_printing_only_number_severity_package(self):
        rc, out = run(pages((200, [alert(4, "critical"), alert(9, "weird", "a b;x")], None))[0])
        self.assertEqual(rc, 3)
        self.assertIn("#4 critical guzzlehttp/guzzle", out)
        self.assertIn("#9 unknown-severity unprintable-package", out)
        self.assertNotIn("ADVISORY", out)

    def test_pagination_follows_link_to_its_end(self):
        p2 = FIRST + "&after=abc"
        get, calls = pages((200, [], p2), (200, [alert(7)], None))
        rc, out = run(get)
        self.assertEqual((rc, calls), (3, [FIRST, p2]))
        self.assertIn("#7 high", out)

    def test_pagination_that_cannot_complete_refuses(self):
        p2 = FIRST + "&after=abc"
        rc, out = run(pages((200, [], p2), (502, b"", None))[0])
        self.assertEqual(rc, 2)
        for nxt in ("https://evil.example/repos/x/dependabot/alerts?a=1", FIRST):
            rc, out = run(pages((200, [], nxt), (200, [], None))[0])
            self.assertEqual(rc, 2, nxt)
            self.assertIn("[branch: pagination]", out)


    def test_a_bad_repo_refuses(self):
        rc, out = run(pages()[0], repo="../x")
        self.assertEqual(rc, 2)
        self.assertIn("[branch: bad-repo]", out)

    def test_a_redirect_is_refused_not_followed(self):
        import urllib.request
        with self.assertRaises(G.Unmeasured) as e:
            G._NoRedirect().redirect_request(urllib.request.Request(FIRST), None, 302, "Found", {},
                                             "https://evil.example/x")
        self.assertEqual(e.exception.branch, "redirect")


if __name__ == "__main__":
    unittest.main()
