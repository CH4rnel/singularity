#!/usr/bin/env python3
"""Serve the NO CARRIER web export locally with cross-origin isolation headers.

Godot 4 web builds that use thread support need COOP/COEP headers
(SharedArrayBuffer). The current export preset disables thread support, so
these headers are harmless but make the build portable either way. The
browser build is also where the web3 wallet (MetaMask / EIP-1193) lives.

    python3 serve_web.py            # http://localhost:8061
    python3 serve_web.py 9000       # custom port
"""
import os
import sys
from functools import partial
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer

PORT = int(sys.argv[1]) if len(sys.argv) > 1 else 8061
WEB_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "build", "web")


class IsolatedHandler(SimpleHTTPRequestHandler):
    def end_headers(self):
        self.send_header("Cross-Origin-Opener-Policy", "same-origin")
        self.send_header("Cross-Origin-Embedder-Policy", "require-corp")
        self.send_header("Cross-Origin-Resource-Policy", "cross-origin")
        super().end_headers()


def main() -> None:
    if not os.path.isfile(os.path.join(WEB_DIR, "index.html")):
        sys.exit(
            "no web build found at %s\n"
            "export it first:  godot4 --headless --export-release \"Web\" build/web/index.html"
            % WEB_DIR
        )
    handler = partial(IsolatedHandler, directory=WEB_DIR)
    server = ThreadingHTTPServer(("", PORT), handler)
    print("serving %s at http://localhost:%d" % (WEB_DIR, PORT))
    server.serve_forever()


if __name__ == "__main__":
    main()
