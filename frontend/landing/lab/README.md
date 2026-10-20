# Cyberia Research / Impossible Matter

Two standalone Three.js / GLSL experiments. SIGNAL remains unchanged at `../signal/`.
No bundler, model assets, transactions or Laravel changes.

```sh
# From the repository root
python3 -m http.server 8765 --directory frontend/landing
```

- http://localhost:8765/lab/?world=reliquary — an endless octagonal vault with
  recursively subdivided side galleries, engraved floor, emissive ribs and a
  continuously folding coordinate field. Travel automatically follows the vault.
- http://localhost:8765/lab/?world=mercury — a morphing toroidal sculpture with
  smoothly merged orbiting blobs, procedural surface disturbances and metallic
  reflections from an analytic HDR studio environment.
- http://localhost:8765/signal/ — the original particle experiment.

## Interaction

Drag to look around (or rotate Mercury), scroll to approach, click the scene or
press Space to send an impulse. Fold / Viscosity changes the shape; Time Flow
changes the simulation rate. Pause freezes scene time while still allowing camera
and control adjustments. An impulse explicitly resumes the scene.

H / Immersive hides the interface. H, Escape or the small Controls button returns
it, including on touch devices. S / Save Frame downloads the canvas as a PNG at
the current viewport resolution. The interface is excluded from the image.
Direct links preserve the selected world, not camera or slider state.

## Rendering

`shaders.js` contains signed-distance functions, sphere tracing, tetrahedral
normals, ambient occlusion and procedural lighting. This is an artistic implicit
surface, not a fluid solver or a physically exact light transport simulation.
`lab.js` runs two GPU passes: the scene into a render target, then edge-aware
antialiasing, threshold glow and subtle chromatic separation onto the canvas.
The post pass shares a reusable render target; world/quality shader variants are
cached. No mesh or texture files are fetched.

Quality changes ray-march iterations and internal resolution:

| Detail | Steps | Initial resolution scale |
| --- | --- | --- |
| Low | 64 | 0.45 |
| Medium | 112 | 0.80 |
| High | 144 | 1.00 |

Internal resolution also accounts for device pixel ratio, with a 1.8-megapixel
ceiling. Medium reduces resolution after sustained low frame rates. Output canvas
size remains unchanged. Time Flow is independent of quality. Rendering stops
while the tab is hidden, and settled paused scenes do not keep rendering.
Reduced-motion preferences start the scene paused; explicit Resume / Impulse can
start it. Changing that preference updates the paused state.

Requires WebGL 2 and internet access to the pinned Three.js 0.180.0 module on
jsDelivr. Google Fonts is optional; local fallback fonts remain usable. Module,
shader and lost-context failures show an explicit message. A context loss requires
a reload. No analytics, audio, wallet connection or recording permissions.

This directory is not wired into the production Laravel landing. To serve it
there, publish the four page assets together under a public path; keep relative
module and stylesheet URLs intact.
