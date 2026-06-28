#!/usr/bin/env python3
"""Serve the Wired web export locally with cross-origin isolation headers.

Godot 4 web builds that use thread support need COOP/COEP headers
(SharedArrayBuffer). This tiny server adds them so the export runs from any
browser. The current export preset disables thread support, so these headers
are harmless but make the build portable either way.

    python3 serve_web.py            # http://localhost:8060
    python3 serve_web.py 9000       # custom port
"""
import os
import sys
from functools import partial
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer

PORT = int(sys.argv[1]) if len(sys.argv) > 1 else 8060
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
            f"No export found at {WEB_DIR}\n"
            "Export the 'Web' preset first:\n"
            "  godot4 --headless --export-release Web build/web/index.html\n"
            "or use the Godot editor: Project > Export > Web > Export Project."
        )
    handler = partial(IsolatedHandler, directory=WEB_DIR)
    server = ThreadingHTTPServer(("127.0.0.1", PORT), handler)
    print(f"Wired is live at http://localhost:{PORT}   (Ctrl+C to stop)")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\nbye.")


if __name__ == "__main__":
    main()
