# README
Addind daemon to systemd:

/etc/systemd/system/singularity-daemon.service:
```
[Unit]
Description=Singularity Daemon
After=network.target

[Service]
Type=simple
ExecStart=/usr/bin/sbcl --noinform --load /home/lain/random/singularity/services/lisp/daemon.lisp --eval "(singularity-daemon:main)"
Restart=always
RestartSec=3

Environment=DAEMON_MODE=prod

StandardOutput=journal
StandardError=journal

KillSignal=SIGTERM

[Install]
WantedBy=multi-user.target
```

```
sudo systemctl daemon-reload
sudo systemctl start singularity-daemon.service
sudo systemctl status singularity-daemon.service
```
Autoloading:
```
sudo systemctl enable singularity-daemon.service
```
Logs:
```
journalctl -u singularity-daemon.service -f
```

## Project Status

This repository is in its earliest stage.
There is no code yet and no formal architecture.

This project starts as an open definition process, where structure, scope, and direction are shaped through discussion and issues.

---

## What is this project?

This project is a starting point for designing a system for integrating and interacting with multiple public APIs through a unified backend interface.

At this stage, it is not a finished system, but a space for defining one.

---

## Purpose

The purpose of this project is to:

* explore how multiple public APIs can be structured under a single interface
* define a consistent way to interact with different external data sources
* design a modular backend architecture for API integration

---

## Scope

### In scope

* Integration of public APIs
* Unified interface for API access
* Adapter-based architecture
* Backend-first implementation (initially Laravel)

### Out of scope (for now)

* User accounts
* API key management for private services
* Paid or authenticated third-party APIs
* Frontend or UI complexity (initially secondary)

---

## Principles

* Simplicity before abstraction
* Incremental design over upfront architecture
* Each API integration should be isolated and replaceable
* The system should evolve through small, testable additions

---

## Current State

### What exists

* Repository structure only
* No codebase yet
* Definition phase via issues

### What is missing

* Backend implementation
* Adapter system
* API routing layer
* Any working functionality

### Usability

Nothing is usable yet.
This is a design and bootstrapping phase.

---

## How to contribute

At this stage, contribution means:

* participating in issue discussions
* helping define architecture and scope
* proposing initial API candidates for integration
* refining the overall direction of the system

### First step for newcomers

Start by reading the issues and contributing to:

* project definition
* architecture decisions
* scope clarification

---

## Notes

This project is intentionally open-ended at this stage.

The goal is not to produce a perfect design upfront, but to let structure emerge through iteration and discussion.

---

## License

GPL-3.0
