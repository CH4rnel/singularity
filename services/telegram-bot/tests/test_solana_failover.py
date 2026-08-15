"""Solana RPC failover: a refused endpoint must cost one call, not a day.

The outage this pins: on 2026-08-14 18:20 the keyed provider began answering
`401 Unauthorized`, and because both Solana surfaces call one URL, pump.fun buy
posts and the whales gate went silent together for 22 hours. The public cluster
answered a server the whole time.

Env is pinned before bot.* imports because bot.config reads it at import time.
"""
import json
import os
import tempfile
import unittest
import urllib.error

_TMP = tempfile.mkdtemp(prefix="solana_test_")
os.environ.setdefault("DB_PATH", os.path.join(_TMP, "solana_test.sqlite"))

from bot import config, solana  # noqa: E402

# The chain under test is pinned here rather than through the environment: any
# other test module that imports bot.config first would have frozen it already.
ENDPOINTS = [
    "https://keyed.example/?api-key=secret",
    "https://second.example",
    "https://api.mainnet-beta.solana.com",
]


class _Body:
    def __init__(self, payload):
        self._payload = json.dumps(payload).encode()

    def read(self):
        return self._payload

    def __enter__(self):
        return self

    def __exit__(self, *_exc):
        return False


def _http_error(code):
    return urllib.error.HTTPError("https://x", code, "nope", {}, None)


class FailoverTest(unittest.TestCase):
    def setUp(self):
        solana._parked.clear()
        self.calls: list[str] = []
        self.addCleanup(setattr, solana, "SOLANA_RPC_URLS", solana.SOLANA_RPC_URLS)
        self.addCleanup(setattr, solana.urllib.request, "urlopen",
                        solana.urllib.request.urlopen)
        solana.SOLANA_RPC_URLS = list(ENDPOINTS)

    def _serve(self, answers):
        """answers: host → payload dict or exception."""
        def urlopen(request, timeout=None):
            url = request.full_url
            self.calls.append(solana.endpoint_host(url))
            answer = answers[solana.endpoint_host(url)]
            if isinstance(answer, Exception):
                raise answer
            return _Body(answer)

        solana.urllib.request.urlopen = urlopen

    def test_config_puts_the_public_cluster_last_and_drops_duplicates(self):
        urls = config._ordered_urls(
            "https://keyed.example ", "", "https://second.example",
            "https://keyed.example", "https://api.mainnet-beta.solana.com",
        )
        self.assertEqual(urls, [
            "https://keyed.example",
            "https://second.example",
            "https://api.mainnet-beta.solana.com",
        ])
        self.assertEqual(config.SOLANA_RPC_URLS[-1], "https://api.mainnet-beta.solana.com")

    def test_a_401_falls_through_to_the_next_endpoint(self):
        self._serve({
            "keyed.example": _http_error(401),
            "second.example": {"result": "ok"},
        })
        self.assertEqual(solana.solana_rpc("getHealth", []), "ok")
        self.assertEqual(self.calls, ["keyed.example", "second.example"])

    def test_a_refused_endpoint_is_parked_and_not_retried(self):
        self._serve({
            "keyed.example": _http_error(401),
            "second.example": {"result": "ok"},
        })
        solana.solana_rpc("getHealth", [])
        self.calls.clear()
        solana.solana_rpc("getHealth", [])
        self.assertEqual(self.calls, ["second.example"])

    def test_a_rate_limit_body_counts_as_a_refusal(self):
        self._serve({
            "keyed.example": {"error": {"code": -32005, "message": "max usage reached"}},
            "second.example": {"result": "ok"},
        })
        self.assertEqual(solana.solana_rpc("getHealth", []), "ok")
        self.assertEqual(self.calls, ["keyed.example", "second.example"])

    def test_a_method_error_is_raised_rather_than_re_asked_elsewhere(self):
        self._serve({
            "keyed.example": {"error": {"code": -32602, "message": "Invalid param"}},
            "second.example": {"result": "ok"},
        })
        with self.assertRaises(RuntimeError):
            solana.solana_rpc("getTransaction", ["nonsense"])
        self.assertEqual(self.calls, ["keyed.example"])

    def test_every_endpoint_down_still_tries_them_all_next_time(self):
        self._serve({
            "keyed.example": _http_error(401),
            "second.example": _http_error(429),
            "api.mainnet-beta.solana.com": _http_error(503),
        })
        with self.assertRaises(urllib.error.HTTPError):
            solana.solana_rpc("getHealth", [])
        self.calls.clear()
        # Parked everywhere is not a reason to answer without asking.
        with self.assertRaises(urllib.error.HTTPError):
            solana.solana_rpc("getHealth", [])
        self.assertEqual(len(self.calls), 3)

    def test_an_endpoint_url_is_never_logged_whole(self):
        # The keyed URL carries an api key in its query string.
        self.assertEqual(solana.endpoint_host("https://keyed.example/?api-key=secret"),
                         "keyed.example")


if __name__ == "__main__":
    unittest.main()
