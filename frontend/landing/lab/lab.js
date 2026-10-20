import { vertexShader, fragmentShader, postShader } from './shaders.js';

const $ = (id) => document.getElementById(id);
const worlds = {
  reliquary: { number: '002', title: 'Reliquary', description: 'A place that goes on<br>after space runs out.', medium: 'RECURSIVE ARCHITECTURE', action: 'BEND REALITY', control: 'FOLD', fold: 35, index: 0 },
  mercury: { number: '003', title: 'Mercury', description: 'It remembers every touch.<br>It has never been solid.', medium: 'IMPOSSIBLE MATERIAL', action: 'DISTURB THE SURFACE', control: 'VISCOSITY', fold: 40, index: 1 },
};
const settings = {
  low: { scale: .45, steps: 64 },
  balanced: { scale: .8, steps: 112 },
  high: { scale: 1, steps: 144 },
};
let toastTimer;
function message(text, duration = 0) {
  clearTimeout(toastTimer);
  $('status').textContent = text;
  if (duration) toastTimer = setTimeout(() => { $('status').textContent = ''; }, duration);
}

try {
  const THREE = await import('https://cdn.jsdelivr.net/npm/three@0.180.0/build/three.module.js');
  const motionPreference = matchMedia('(prefers-reduced-motion: reduce)');
  const renderer = new THREE.WebGLRenderer({ canvas: $('viewport'), antialias: false, preserveDrawingBuffer: true, powerPreference: 'high-performance' });
  // The shaders produce display-encoded output themselves.
  renderer.outputColorSpace = THREE.LinearSRGBColorSpace;
  renderer.setPixelRatio(Math.min(devicePixelRatio, 1.5));
  renderer.debug.onShaderError = (_gl, _program, vertex, fragment) => {
    console.error('Shader compilation failed:', _gl.getShaderInfoLog(vertex), _gl.getShaderInfoLog(fragment));
    throw new Error('The graphics shader could not compile on this device.');
  };
  const scene = new THREE.Scene();
  const postScene = new THREE.Scene();
  const camera = new THREE.Camera();
  const plane = new THREE.PlaneGeometry(2, 2);
  const target = new THREE.WebGLRenderTarget(1, 1, { depthBuffer: false, stencilBuffer: false });
  const uniforms = {
    uResolution: { value: new THREE.Vector2(1, 1) },
    uLook: { value: new THREE.Vector2() },
    uTouch: { value: new THREE.Vector2() },
    uTime: { value: 8 }, uTravel: { value: 0 },
    uFold: { value: .35 }, uImpulse: { value: 0 }, uZoom: { value: 0 },
    uMobile: { value: innerWidth < 750 ? 1 : 0 }, uFraming: { value: 1 },
  };
  const materials = new Map();
  function getMaterial(world, quality) {
    const key = `${world}:${quality}`;
    if (!materials.has(key)) {
      materials.set(key, new THREE.ShaderMaterial({
        vertexShader, fragmentShader, uniforms,
        defines: { WORLD: worlds[world].index, MARCH_STEPS: settings[quality].steps },
        depthTest: false, depthWrite: false,
      }));
    }
    return materials.get(key);
  }
  let world = Object.hasOwn(worlds, new URLSearchParams(location.search).get('world')) ? new URLSearchParams(location.search).get('world') : 'reliquary';
  let quality = 'balanced', adaptiveScale = 1, framing = 1;
  let paused = motionPreference.matches, ready = false, dirty = true, failed = false;
  let time = 8, travel = 0, last = 0, impulseAge = 100, speed = 1, zoom = 0;
  let frames = 0, measureTime = 0, slowWindows = 0, lastReadout = 0;
  const look = new THREE.Vector2();
  const mesh = new THREE.Mesh(plane, getMaterial(world, quality));
  mesh.frustumCulled = false;
  scene.add(mesh);
  const postUniforms = { uFrame: { value: target.texture }, uTexel: { value: new THREE.Vector2(1, 1) }, uImpulse: uniforms.uImpulse };
  const postMaterial = new THREE.ShaderMaterial({ vertexShader, fragmentShader: postShader, uniforms: postUniforms, depthTest: false, depthWrite: false });
  const postMesh = new THREE.Mesh(plane, postMaterial);
  postMesh.frustumCulled = false;
  postScene.add(postMesh);

  function resize() {
    renderer.setSize(innerWidth, innerHeight);
    const pixelRatio = Math.min(devicePixelRatio, 1.25);
    const scale = settings[quality].scale * adaptiveScale * pixelRatio;
    const ceiling = Math.min(1, Math.sqrt(1_800_000 / (innerWidth * innerHeight * scale * scale)));
    const width = Math.max(1, Math.round(innerWidth * scale * ceiling));
    const height = Math.max(1, Math.round(innerHeight * scale * ceiling));
    target.setSize(width, height);
    uniforms.uResolution.value.set(width, height);
    uniforms.uMobile.value = innerWidth < 750 ? 1 : 0;
    postUniforms.uTexel.value.set(1 / width, 1 / height);
    dirty = true;
  }
  function syncPause() {
    $('pause').textContent = paused ? '▶ RESUME' : 'Ⅱ PAUSE';
    $('pause').setAttribute('aria-pressed', String(paused));
    $('live').textContent = paused || speed === 0 ? '● STILL' : '● LIVE';
    dirty = true;
  }
  function resetView() {
    look.set(0, 0); zoom = 0;
    uniforms.uLook.value.set(0, 0);
    uniforms.uZoom.value = 0;
    uniforms.uTouch.value.set(0, 0);
    dirty = true;
  }
  function selectWorld(next, updateUrl = true) {
    if (!Object.hasOwn(worlds, next)) return;
    world = next;
    const info = worlds[world];
    document.body.dataset.world = world;
    document.title = `${info.title} — CYBERIA RESEARCH`;
    $('number').textContent = info.number;
    $('title').textContent = info.title;
    $('description').innerHTML = info.description;
    $('medium').textContent = info.medium;
    $('impulse').innerHTML = `${info.action} <span>↗</span>`;
    $('intensity-label').textContent = info.control;
    $('intensity').value = info.fold;
    $('intensity-value').textContent = `${info.fold}%`;
    uniforms.uFold.value = info.fold / 100;
    for (const button of document.querySelectorAll('.world')) {
      button.classList.toggle('active', button.dataset.world === world);
      button.setAttribute('aria-pressed', String(button.dataset.world === world));
    }
    mesh.material = getMaterial(world, quality);
    time = 8; travel = 0; impulseAge = 100;
    resetView();
    if (updateUrl) {
      const url = new URL(location.href);
      url.searchParams.set('world', world);
      history.replaceState(null, '', url);
    }
    dirty = true;
  }
  function impulse() {
    if (!ready || failed) return;
    impulseAge = 0;
    // An explicit interaction is the user's choice to resume motion.
    paused = false;
    if (speed === 0) { speed = 1; $('speed').value = '100'; $('speed-value').textContent = '1.0×'; }
    syncPause();
  }
  function immersive(hide) {
    document.body.classList.toggle('immersive', hide);
    framing = hide ? 0 : 1;
    if (motionPreference.matches) uniforms.uFraming.value = framing;
    dirty = true;
    $('restore').hidden = !hide;
    // Opacity alone would leave invisible controls in the keyboard tab order.
    for (const el of document.querySelectorAll('.chrome')) el.inert = hide;
    if (hide) { $('restore').focus({ preventScroll: true }); message('H / ESC — RETURN TO CONTROLS', 2400); }
    else { message(''); $('hide').focus({ preventScroll: true }); }
  }
  function draw() {
    renderer.setRenderTarget(target);
    renderer.render(scene, camera);
    renderer.setRenderTarget(null);
    renderer.render(postScene, camera);
  }
  async function save() {
    if (!ready || failed) return;
    try {
      draw();
      const blob = await new Promise((resolve) => renderer.domElement.toBlob(resolve, 'image/png'));
      if (!blob) throw new Error('PNG encoding failed');
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.download = `cyberia-${world}-${Math.floor(time)}.png`;
      link.href = url; link.click();
      setTimeout(() => URL.revokeObjectURL(url), 1500);
      message('FRAME SAVED', 1800);
    } catch (error) { console.error(error); message('Could not save the frame. Try again.'); }
  }
  document.querySelectorAll('.world').forEach((button) => button.addEventListener('click', () => selectWorld(button.dataset.world)));
  $('impulse').onclick = impulse;
  $('pause').onclick = () => { paused = !paused; syncPause(); };
  $('hide').onclick = () => immersive(true);
  $('restore').onclick = () => immersive(false);
  $('reset').onclick = resetView;
  $('save').onclick = save;
  $('intensity').oninput = (event) => {
    uniforms.uFold.value = Number(event.target.value) / 100;
    $('intensity-value').textContent = `${event.target.value}%`;
    dirty = true;
  };
  $('speed').oninput = (event) => {
    speed = Number(event.target.value) / 100;
    $('speed-value').textContent = `${speed.toFixed(1)}×`;
    syncPause();
  };
  $('quality').onchange = (event) => {
    quality = event.target.value; adaptiveScale = 1;
    frames = 0; measureTime = 0; slowWindows = 0;
    mesh.material = getMaterial(world, quality);
    resize();
  };
  let drag = null;
  const canvas = renderer.domElement;
  canvas.addEventListener('pointerdown', (event) => {
    if (event.button !== 0 || drag) return;
    drag = { id: event.pointerId, x: event.clientX, y: event.clientY, distance: 0 };
    canvas.setPointerCapture(event.pointerId);
  });
  canvas.addEventListener('pointermove', (event) => {
    uniforms.uTouch.value.set(event.clientX / innerWidth * 2 - 1, 1 - event.clientY / innerHeight * 2);
    if (!drag || drag.id !== event.pointerId) return;
    const dx = event.clientX - drag.x, dy = event.clientY - drag.y;
    drag.distance += Math.abs(dx) + Math.abs(dy);
    look.x = THREE.MathUtils.clamp(look.x - dx * .005, -1.5, 1.5);
    look.y = THREE.MathUtils.clamp(look.y + dy * .004, -1, 1);
    drag.x = event.clientX; drag.y = event.clientY;
    dirty = true;
  });
  canvas.addEventListener('pointerup', (event) => {
    if (!drag || drag.id !== event.pointerId) return;
    if (drag.distance < 6) impulse();
    drag = null;
    canvas.releasePointerCapture(event.pointerId);
  });
  for (const type of ['pointercancel', 'lostpointercapture']) canvas.addEventListener(type, () => { drag = null; });
  canvas.addEventListener('wheel', (event) => {
    event.preventDefault();
    zoom = THREE.MathUtils.clamp(zoom + event.deltaY * .003, -2.5, 3);
    dirty = true;
  }, { passive: false });
  addEventListener('keydown', (event) => {
    if (event.repeat || event.metaKey || event.ctrlKey || event.altKey) return;
    if (event.key === 'Escape') { immersive(false); return; }
    if (/^(INPUT|SELECT|TEXTAREA)$/.test(event.target.tagName)) return;
    if (event.code === 'Space' && !/^(BUTTON|A)$/.test(event.target.tagName)) { event.preventDefault(); impulse(); }
    if (event.key.toLowerCase() === 'h') immersive(!document.body.classList.contains('immersive'));
    if (event.key.toLowerCase() === 's') save();
  });
  motionPreference.addEventListener('change', (event) => { paused = event.matches; syncPause(); });
  addEventListener('resize', resize);
  document.addEventListener('visibilitychange', () => { last = 0; frames = 0; measureTime = 0; dirty = true; });
  function fail(error) {
    failed = true;
    renderer.setAnimationLoop(null);
    document.body.dataset.ready = 'error';
    message(error.message || 'Graphics interrupted. Reload to reconnect.');
    for (const el of document.querySelectorAll('button,input,select')) el.disabled = true;
    console.error('Cyberia Research:', error);
  }
  canvas.addEventListener('webglcontextlost', (event) => { event.preventDefault(); fail(new Error('Graphics connection lost. Reload the page to reconnect.')); });
  selectWorld(world, false);
  syncPause(); resize();
  renderer.setAnimationLoop((now) => {
    if (failed || document.hidden) return;
    const elapsed = last ? (now - last) / 1000 : 0;
    const dt = Math.min(elapsed, .075);
    last = now;
    const moving = !paused && speed > 0;
    if (!moving && !dirty) return;
    if (moving) {
      time += dt * speed;
      // Travel is integrated instead of multiplying by speed to avoid jumps.
      travel -= dt * speed * .85;
      impulseAge += dt * speed;
    }
    const lerp = 1 - Math.exp(-Math.max(dt, .016) * 9);
    uniforms.uLook.value.lerp(look, lerp);
    uniforms.uZoom.value += (zoom - uniforms.uZoom.value) * lerp;
    uniforms.uFraming.value += (framing - uniforms.uFraming.value) * lerp;
    uniforms.uTime.value = time;
    uniforms.uTravel.value = travel;
    uniforms.uImpulse.value = Math.exp(-impulseAge * 1.7);
    try { draw(); } catch (error) { fail(error); return; }
    // Keep rendering after a paused drag until the camera has settled.
    dirty = uniforms.uLook.value.distanceTo(look) > .001 || Math.abs(uniforms.uZoom.value - zoom) > .001 || Math.abs(uniforms.uFraming.value - framing) > .001;
    if (!ready) { ready = true; document.body.dataset.ready = 'true'; message(''); }
    if (now - lastReadout > 180) {
      $('coordinates').textContent = world === 'reliquary' ? `Z ${travel.toFixed(2).padStart(8, '0')}` : `t ${time.toFixed(2).padStart(8, '0')}`;
      lastReadout = now;
    }
    // Balanced mode reduces render-target resolution after sustained low FPS.
    // It never changes animation speed, geometry detail or the saved output size.
    if (quality === 'balanced' && moving && elapsed > 0 && elapsed < 2) {
      frames++; measureTime += elapsed;
      if (measureTime >= 2) {
        slowWindows = frames / measureTime < 26 ? slowWindows + 1 : 0;
        if (slowWindows >= 2 && adaptiveScale > .55) { adaptiveScale = Math.max(.55, adaptiveScale * .8); resize(); slowWindows = 0; }
        frames = 0; measureTime = 0;
      }
    }
  });
} catch (error) {
  document.body.dataset.ready = 'error';
  message('The experiment could not start. Check your internet connection and WebGL 2 support.');
  for (const el of document.querySelectorAll('button,input,select')) el.disabled = true;
  console.error('Cyberia Research initialization:', error);
}
