const $ = (id) => document.getElementById(id);
const notice = (message) => { $('notice').textContent = message; };
try {
  const THREE = await import('https://cdn.jsdelivr.net/npm/three@0.180.0/build/three.module.js');
  const reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const renderer = new THREE.WebGLRenderer({ canvas: $('field'), antialias: true, preserveDrawingBuffer: true });
  renderer.setPixelRatio(Math.min(devicePixelRatio, 1.75));
  renderer.setClearColor(0x050708);
  const scene = new THREE.Scene();
  scene.fog = new THREE.FogExp2(0x050708, 0.022);
  const camera = new THREE.PerspectiveCamera(48, innerWidth / innerHeight, 0.1, 200);
  camera.position.set(0, 5, 26);
  const field = new THREE.Group();
  field.position.set(5, 0, 0);
  field.rotation.x = 0.34;
  scene.add(field);
  const uniforms = { time: { value: 0 }, pulse: { value: -100 }, pointer: { value: new THREE.Vector2() }, rift: { value: 0 } };
  const count = innerWidth < 650 ? 28000 : 65000;
  const positions = new Float32Array(count * 3);
  const seeds = new Float32Array(count);
  // Uniform area density, with a second population tracing the accretion disk.
  for (let i = 0; i < count; i++) {
    const r = i % 3 === 0 ? 3.5 + Math.random() * 3 : 3.4 + Math.pow(Math.random(), 0.7) * 22;
    const a = Math.random() * Math.PI * 2;
    positions[i * 3] = Math.cos(a) * r;
    positions[i * 3 + 1] = (Math.random() - 0.5) * (i % 3 === 0 ? 0.16 : 0.7);
    positions[i * 3 + 2] = Math.sin(a) * r;
    seeds[i] = Math.random();
  }
  const geometry = new THREE.BufferGeometry();
  geometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));
  geometry.setAttribute('seed', new THREE.BufferAttribute(seeds, 1));
  const material = new THREE.ShaderMaterial({
    uniforms, transparent: true, depthWrite: false, blending: THREE.AdditiveBlending,
    vertexShader: `attribute float seed; uniform float time; uniform float pulse; uniform vec2 pointer; uniform float rift; varying float vLight; varying float vSeed;
    void main(){vec3 p=position;float r=length(p.xz);float a=atan(p.z,p.x)+time*(.035+1.0/(r*r))*(1.0+rift*3.0);float wave=sin(r*1.3-time*.7+seed*2.0);p.x=cos(a)*r;p.z=sin(a)*r;p.y+=wave*.16-3.5*exp(-r*.26);float age=time-pulse;float shock=exp(-pow((r-3.5-age*8.0)*.75,2.0))*step(0.0,age);p.y+=shock*(2.0+rift);p.y+=sin(a*3.0+time)*rift*.45;p.x+=pointer.x*seed*.4;p.z+=pointer.y*seed*.4;vec4 mv=modelViewMatrix*vec4(p,1.0);gl_Position=projectionMatrix*mv;gl_PointSize=clamp((22.0+seed*19.0)/-mv.z,1.0,4.0);vLight=.3+seed*.6+shock*1.8;vSeed=seed;}`,
    fragmentShader: `varying float vLight;varying float vSeed;uniform float rift;void main(){float d=length(gl_PointCoord-.5);if(d>.5)discard;vec3 lime=mix(vec3(.30,.53,.34),vec3(.83,1.0,.47),vSeed);vec3 col=mix(lime,vec3(.45,.64,1.0),rift*.8);gl_FragColor=vec4(col*vLight,pow(1.0-d*2.0,1.5));}`,
  });
  field.add(new THREE.Points(geometry, material));
  const blackSun = new THREE.Mesh(new THREE.SphereGeometry(3.35, 64, 48), new THREE.MeshBasicMaterial({ color: 0x020404 }));
  blackSun.position.y = -0.5;
  field.add(blackSun);
  const halo = new THREE.Mesh(new THREE.TorusGeometry(3.48, 0.018, 8, 240), new THREE.MeshBasicMaterial({ color: 0xd4ff99 }));
  halo.position.y = -0.5;
  halo.rotation.x = Math.PI / 2;
  field.add(halo);
  const orbit = new THREE.Group();
  for (let i = 0; i < 3; i++) {
    const ring = new THREE.Mesh(new THREE.TorusGeometry(3.6 + i * .13, .006, 4, 180), new THREE.MeshBasicMaterial({ color: 0x8acb87, transparent: true, opacity: .28 - i * .06 }));
    ring.rotation.set(Math.PI / 2 + .12 * i, .3 * i, 0);
    orbit.add(ring);
  }
  field.add(orbit);
  const starsGeo = new THREE.BufferGeometry();
  const stars = new Float32Array(1800 * 3);
  for (let i = 0; i < stars.length; i++) stars[i] = (Math.random() - .5) * 150;
  starsGeo.setAttribute('position', new THREE.BufferAttribute(stars, 3));
  scene.add(new THREE.Points(starsGeo, new THREE.PointsMaterial({ color: 0x9bac9f, size: .035, transparent: true, opacity: .5 })));
  const grid = new THREE.GridHelper(160, 80, 0x264033, 0x12251e);
  grid.position.y = -4.5;
  scene.add(grid);
  let time = 0, previous = 0, paused = reduced, rift = false, signals = 0;
  const pointer = new THREE.Vector2();
  function syncPause() { $('motion').textContent = paused ? 'RESUME' : 'PAUSE'; $('motion').setAttribute('aria-pressed', String(paused)); }
  syncPause();
  function pulse() {
    if (paused) { paused = false; syncPause(); }
    uniforms.pulse.value = time;
    signals++;
    $('count').textContent = String(signals).padStart(3, '0');
    $('state').textContent = 'SIGNAL PROPAGATING';
  }
  $('pulse').onclick = pulse;
  $('field').addEventListener('click', pulse);
  $('mode').onclick = () => { rift = !rift; $('mode').textContent = rift ? 'LEAVE THE RIFT' : 'ENTER THE RIFT'; $('mode').setAttribute('aria-pressed', String(rift)); pulse(); };
  $('motion').onclick = () => { paused = !paused; syncPause(); };
  $('clean').onclick = () => { document.body.classList.toggle('clean'); notice('Press H or Escape to restore interface'); setTimeout(() => notice(''), 3500); };
  function save() {
    renderer.render(scene, camera);
    $('field').toBlob((blob) => {
      if (!blob) return notice('Could not save this frame.');
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a'); link.download = 'cyberia-signal.png'; link.href = url; link.click();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    });
  }
  $('save').onclick = save;
  addEventListener('keydown', (event) => {
    if (event.repeat || event.ctrlKey || event.metaKey || event.altKey || /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement?.tagName ?? '')) return;
    if (event.code === 'Space' && !/^(BUTTON|A)$/.test(document.activeElement?.tagName ?? '')) { event.preventDefault(); pulse(); }
    if (event.key.toLowerCase() === 'h') $('clean').click();
    if (event.key === 'Escape') { document.body.classList.remove('clean'); notice(''); }
    if (event.key.toLowerCase() === 's') save();
  });
  addEventListener('pointermove', (event) => pointer.set(event.clientX / innerWidth * 2 - 1, event.clientY / innerHeight * 2 - 1));
  function resize() {
    camera.aspect = innerWidth / innerHeight; camera.updateProjectionMatrix();
    renderer.setSize(innerWidth, innerHeight);
    field.position.x = innerWidth < 650 ? 1.5 : 5;
  }
  addEventListener('resize', resize); resize();
  renderer.domElement.addEventListener('webglcontextlost', (event) => { event.preventDefault(); renderer.setAnimationLoop(null); notice('Graphics context interrupted. Reload to reconnect.'); });
  renderer.setAnimationLoop((now) => {
    const dt = Math.min((now - previous) / 1000 || 0, .05); previous = now;
    if (document.hidden) return;
    if (!paused) time += dt;
    uniforms.time.value = time;
    const blend = 1 - Math.exp(-dt * 3);
    uniforms.rift.value += ((rift ? 1 : 0) - uniforms.rift.value) * blend;
    uniforms.pointer.value.lerp(pointer, blend);
    camera.position.x += (pointer.x * .8 - camera.position.x) * blend;
    camera.position.y += (5 + pointer.y * .7 - camera.position.y) * blend;
    camera.lookAt(0, 0, 0);
    field.rotation.z = Math.sin(time * .09) * .06;
    orbit.rotation.z = time * .06;
    if (time - uniforms.pulse.value > 4) $('state').textContent = rift ? 'RIFT STABILIZED' : 'AWAITING INPUT';
    renderer.render(scene, camera);
  });
} catch (error) {
  notice('The field could not initialize. Check your connection and WebGL support.');
  for (const button of document.querySelectorAll('button')) button.disabled = true;
  console.error('Signal initialization failed:', error);
}
