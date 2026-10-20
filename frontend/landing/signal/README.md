# CYBERIA / SIGNAL

Standalone interactive Three.js experiment: a shader-driven accretion disk,
black sun, gravitational ripples and an alternate blue Rift state.
No build step. No wallet, chain transactions or live network metrics.

From the repository root:

```sh
python3 -m http.server 8765 --directory frontend/landing
```

Open http://localhost:8765/signal/.

- Move the pointer to shift the field; click the scene or press Space for a ripple.
- Enter the Rift to change the palette and orbital speed.
- H hides the interface; H or Escape restores it.
- S / Save frame exports the current canvas as PNG (without the HTML interface).
- Pause stops scene time. Reduced-motion preferences start the scene paused.

For a clip, hide the interface, record the browser, send a few ripples and
switch to Rift. The scene has no audio and does not request recording permissions.

Three.js is pinned to 0.180.0 and loaded from jsDelivr; fonts use Google Fonts.
First load requires internet access and WebGL. An initialization failure displays
an explicit message. This page is independent of the production Laravel landing;
serving it there requires copying these assets into its public web root.
