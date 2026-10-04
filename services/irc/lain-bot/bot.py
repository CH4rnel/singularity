"""lain on irc.cyberia.church.

Holds one IRC connection, sits in the configured channels and forwards every
line addressed to her to Laravel (POST /api/irc/lain), which answers with the
same tool-less persona as /lain and the wallet. This process holds no model
key and no wallet; it holds an IRC account password and the shared token for
that one endpoint, both from the environment.

Addressed means: a private query, or a channel line that names her
(`lain: ...`, `lain, ...`, or `lain` / `лейн` anywhere as a word).

Standard library only, so the container is the stock python image and nothing
is installed at start.
"""

from __future__ import annotations

import base64
import json
import os
import queue
import re
import socket
import ssl
import threading
import time
import urllib.error
import urllib.request
from collections import deque

HOST = os.environ.get("IRC_HOST", "ergo")
PORT = int(os.environ.get("IRC_PORT", "6697"))
# The certificate is irc.cyberia.church's even when the TCP connection goes to
# the compose service name, so verify against the public name.
TLS_NAME = os.environ.get("IRC_TLS_NAME", "irc.cyberia.church")
NICK = os.environ.get("IRC_LAIN_NICK", "lain")
PASSWORD = os.environ.get("IRC_LAIN_PASSWORD", "")
CHANNELS = [c.strip() for c in os.environ.get("IRC_LAIN_CHANNELS", "#cyberia").split(",") if c.strip()]
API_URL = os.environ.get("LAIN_API_URL", "https://cyberia.church/api/irc/lain")
API_TOKEN = os.environ.get("IRC_LAIN_TOKEN", "")

CONTEXT_LINES = 20  # mirrors IrcLainController::CONTEXT_MESSAGES
MAX_REPLY_LINES = 6  # beyond this a reply floods the channel
LINE_BYTES = 400  # an IRC line is 512 bytes including the prefix and command
COOLDOWN_SECONDS = 3  # per nick, so one person cannot queue the room away
QUEUE_LIMIT = 10
API_TIMEOUT = 200  # Laravel may try two models at up to 90s each
IGNORED_SENDERS = {"nickserv", "chanserv", "histserv", "hostserv"}

MENTION = re.compile(r"(?<![\w-])(lain|лейн)(?![\w-])", re.IGNORECASE)
ADDRESS = re.compile(r"^\s*(lain|лейн)\s*[:,]\s*", re.IGNORECASE)


def log(message: str) -> None:
    print(time.strftime("%Y-%m-%dT%H:%M:%S"), message, flush=True)


class Bot:
    def __init__(self) -> None:
        self.sock: ssl.SSLSocket | None = None
        self.send_lock = threading.Lock()
        self.nick = NICK
        self.sasl_ok = False
        self.history: dict[str, deque] = {}
        self.jobs: queue.Queue = queue.Queue(maxsize=QUEUE_LIMIT)
        self.last_asked: dict[str, float] = {}
        threading.Thread(target=self.worker, daemon=True).start()

    # ------------------------------------------------------------ transport

    def send(self, line: str) -> None:
        data = (line.replace("\r", " ").replace("\n", " ") + "\r\n").encode("utf-8", "replace")
        with self.send_lock:
            if self.sock is not None:
                self.sock.sendall(data)

    def connect(self) -> None:
        context = ssl.create_default_context()
        raw = socket.create_connection((HOST, PORT), timeout=30)
        self.sock = context.wrap_socket(raw, server_hostname=TLS_NAME)
        self.sock.settimeout(300)
        self.nick = NICK
        self.sasl_ok = False
        log(f"connected to {HOST}:{PORT}")
        if PASSWORD:
            self.send("CAP REQ :sasl")
        self.send(f"NICK {NICK}")
        self.send(f"USER {NICK} 0 * :Lain of the Wired")

    def run_forever(self) -> None:
        delay = 5
        while True:
            try:
                self.connect()
                delay = 5
                self.read_loop()
            except Exception as error:  # the bot must outlive any one connection
                log(f"connection lost: {error!r}")
            finally:
                try:
                    if self.sock is not None:
                        self.sock.close()
                finally:
                    self.sock = None
            time.sleep(delay)
            delay = min(delay * 2, 300)

    def read_loop(self) -> None:
        buffer = b""
        assert self.sock is not None
        while True:
            chunk = self.sock.recv(4096)
            if not chunk:
                raise ConnectionError("server closed the connection")
            buffer += chunk
            while b"\r\n" in buffer:
                raw, buffer = buffer.split(b"\r\n", 1)
                self.handle(raw.decode("utf-8", "replace"))

    # ------------------------------------------------------------ protocol

    def handle(self, line: str) -> None:
        prefix = ""
        if line.startswith(":"):
            prefix, _, line = line[1:].partition(" ")
        if " :" in line:
            head, trailing = line.split(" :", 1)
            params = head.split() + [trailing]
        else:
            params = line.split()
        if not params:
            return
        command, args = params[0].upper(), params[1:]

        if command == "PING":
            self.send(f"PONG :{args[0] if args else ''}")
        elif command == "CAP" and len(args) >= 3 and args[1] == "ACK" and "sasl" in args[2]:
            self.send("AUTHENTICATE PLAIN")
        elif command == "AUTHENTICATE" and args and args[0] == "+":
            blob = f"{NICK}\0{NICK}\0{PASSWORD}".encode()
            self.send("AUTHENTICATE " + base64.b64encode(blob).decode())
        elif command in ("903", "904", "905", "906", "907"):
            # 903 = logged in; anything else = no account yet, registered after 001.
            self.sasl_ok = command == "903"
            log("sasl: logged in" if self.sasl_ok else f"sasl: {command}, will register")
            self.send("CAP END")
        elif command == "CAP" and len(args) >= 2 and args[1] == "NAK":
            self.send("CAP END")
        elif command == "433":  # nick in use: take a spare, then reclaim it
            self.nick = self.nick + "_"
            self.send(f"NICK {self.nick}")
            if PASSWORD:
                self.send(f"PRIVMSG NickServ :GHOST {NICK}")
                self.send(f"NICK {NICK}")
        elif command == "NICK" and prefix.split("!")[0] == self.nick and args:
            self.nick = args[0]
        elif command == "001":
            if PASSWORD and not self.sasl_ok:
                self.send(f"PRIVMSG NickServ :REGISTER {PASSWORD}")
            for channel in CHANNELS:
                self.send(f"JOIN {channel}")
            log(f"online as {self.nick}, joining {', '.join(CHANNELS)}")
        elif command == "PRIVMSG" and len(args) >= 2:
            self.on_privmsg(prefix.split("!")[0], args[0], args[1])

    def on_privmsg(self, sender: str, target: str, text: str) -> None:
        if not sender or sender.lower() in IGNORED_SENDERS or sender == self.nick:
            return
        if text.startswith("\x01"):  # CTCP / ACTION
            return
        private = not target.startswith(("#", "&"))
        room = sender if private else target
        lines = self.history.setdefault(room.lower(), deque(maxlen=CONTEXT_LINES))
        context = list(lines)
        lines.append({"nick": sender, "text": text})

        if not private and not MENTION.search(text):
            return
        question = ADDRESS.sub("", text).strip() or text.strip()
        now = time.monotonic()
        if now - self.last_asked.get(sender.lower(), 0) < COOLDOWN_SECONDS:
            return
        self.last_asked[sender.lower()] = now
        try:
            self.jobs.put_nowait((room, sender, question, context, private))
        except queue.Full:
            self.say(room, sender, "too many voices at once. ask me again in a minute.", private)

    # ------------------------------------------------------------ answers

    def worker(self) -> None:
        while True:
            room, sender, question, context, private = self.jobs.get()
            try:
                reply = self.ask(room, sender, question, context)
            except Exception as error:
                log(f"ask failed: {error!r}")
                reply = "…the wired flickered. try again in a moment."
            self.say(room, sender, reply, private)
            self.history.setdefault(room.lower(), deque(maxlen=CONTEXT_LINES)).append(
                {"nick": "lain", "text": reply}
            )

    def ask(self, room: str, sender: str, question: str, context: list) -> str:
        body = json.dumps(
            {"target": room, "nick": sender, "text": question[:2000], "history": context}
        ).encode()
        request = urllib.request.Request(
            API_URL,
            data=body,
            headers={
                "Content-Type": "application/json",
                "Accept": "application/json",
                "X-Irc-Token": API_TOKEN,
                "User-Agent": "cyberia-irc-lain/1",
            },
            method="POST",
        )
        try:
            with urllib.request.urlopen(request, timeout=API_TIMEOUT) as response:
                text = json.load(response).get("text") or ""
        except urllib.error.HTTPError as error:
            log(f"api answered {error.code}")
            if error.code == 429:
                return "slow down a little. ask me again in a minute."
            return "…the wired flickered. try again in a moment."
        return text.strip() or "…"

    def say(self, room: str, sender: str, text: str, private: bool) -> None:
        lines = to_irc_lines(text)
        if len(lines) > MAX_REPLY_LINES:
            lines = lines[:MAX_REPLY_LINES]
            lines[-1] = lines[-1].rstrip() + " …"
        for index, line in enumerate(lines):
            prefix = f"{sender}: " if index == 0 and not private else ""
            self.send(f"PRIVMSG {room} :{prefix}{line}")
            time.sleep(0.4)  # stay under Ergo's fakelag instead of tripping it


def to_irc_lines(text: str) -> list[str]:
    """Plain lines no longer than LINE_BYTES, markdown residue removed."""
    text = re.sub(r"```[a-zA-Z]*", "", text)
    text = re.sub(r"\*\*(.+?)\*\*", r"\1", text)
    out: list[str] = []
    for paragraph in text.splitlines():
        paragraph = re.sub(r"^#+\s*", "", paragraph).strip()
        if not paragraph:
            continue
        current = ""
        for word in paragraph.split(" "):
            candidate = f"{current} {word}".strip()
            if len(candidate.encode()) > LINE_BYTES and current:
                out.append(current)
                current = word
            else:
                current = candidate
        while len(current.encode()) > LINE_BYTES:  # one enormous word
            cut = current.encode()[:LINE_BYTES].decode("utf-8", "ignore")
            out.append(cut)
            current = current[len(cut):]
        if current:
            out.append(current)
    return out or ["…"]


if __name__ == "__main__":
    if not API_TOKEN:
        raise SystemExit("IRC_LAIN_TOKEN is not set")
    Bot().run_forever()
