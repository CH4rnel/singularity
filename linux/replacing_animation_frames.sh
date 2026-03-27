#!/bin/bash

THEME_DIR="/usr/share/plymouth/themes/mint-logo"
LOGO="/root/cyberia-logo.png"

cd "$THEME_DIR" || exit

echo "Replacing animation frames..."

for f in animation-*.png; do
    cp "$LOGO" "$f"
done

echo "Replacing throbber frames..."

for f in throbber-*.png; do
    cp "$LOGO" "$f"
done

echo "Done."
