#!/usr/bin/env python3
"""curses-based 'Hello World' application.

This module demonstrates a clean, minimal curses application following
SRP, DRY, and KISS principles.
"""

import curses
import sys


def display_hello(stdscr: curses.window) -> None:
    """Render 'Hello World' and wait for user input.

    Args:
        stdscr: The curses window object covering the entire screen.
    """
    stdscr.clear()
    height, width = stdscr.getmaxyx()
    message = "Hello World"
    x = width // 2 - len(message) // 2
    y = height // 2
    stdscr.addstr(y, x, message)

    prompt = "Press any key to exit"
    stdscr.addstr(height - 2, width // 2 - len(prompt) // 2, prompt)

    stdscr.refresh()
    stdscr.getch()


def run() -> None:
    """Initialize curses and run the main display logic."""
    curses.wrapper(display_hello)


if __name__ == "__main__":
    if sys.platform == "win32":
        try:
            import curses
        except ImportError:
            print(
                "curses not available on Windows without additional libraries.",
                file=sys.stderr,
            )
            print("Consider installing 'windows-curses' via pip.", file=sys.stderr)
            sys.exit(1)
    run()
