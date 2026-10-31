import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { currentPlace, initPlaceSearch } from './place.js';

const $ = id => document.getElementById(id);

// ---------------------------------------------------------------- Daten laden
async function loadJSON(url, onWait) {
  for (;;) {
    const r = await fetch(url);
    if (r.status === 202) {
      const s = await r.json();
      onWait?.(s);
      await new Promise(res => setTimeout(res, 2500));
      continue;
    }
    if (!r.ok) throw new Error((await r.json().catch(() => null))?.message || `${url}: HTTP ${r.status}`);
    return r.json();
  }
}

function setLoading(msg) { $('loadmsg').textContent = msg; }

const place = currentPlace();
initPlaceSearch(place);
document.title = `Wolken 3D – ${place.name}`;
const qs = `?lat=${place.lat}&lon=${place.lon}`;

const [terrain, clouds] = await Promise.all([
  loadJSON('api/terrain.php' + qs),
  loadJSON('api/clouds.php' + qs, s => setLoading(s.total
    ? `Server lädt ICON-D2 … ${s.done} / ${s.total} Dateien`
    : 'Server lädt ICON-D2 …')),
]).catch(e => { setLoading(e.message + ' Bitte oben einen anderen Ort wählen.'); throw e; });
$('loading').classList.add('hidden');

// ---------------------------------------------------------------- Szene
const canvas = $('view');
const renderer = new THREE.WebGLRenderer({ canvas, antialias: true });
renderer.setPixelRatio(Math.min(devicePixelRatio, 2));
const scene = new THREE.Scene();
scene.background = new THREE.Color(0x8fb4d6);
const camera = new THREE.PerspectiveCamera(45, 1, 100, 200000);
camera.position.set(-14000, 9000, 16000);

const controls = new OrbitControls(camera, canvas);
controls.target.set(0, 800, 0);
controls.enableDamping = true;
controls.maxPolarAngle = Math.PI * 0.495;
controls.minDistance = 1500;
controls.maxDistance = 70000;
controls.update();

scene.add(new THREE.HemisphereLight(0xffffff, 0x556644, 1.1));
const sun = new THREE.DirectionalLight(0xfff4e0, 1.6);
sun.position.set(-8000, 12000, 6000);
scene.add(sun);

// Alles in einer Gruppe, damit die Überhöhung (y-Skalierung) einheitlich wirkt.
// Koordinaten: x = Ost, y = Höhe, z = -Nord
const world = new THREE.Group();
scene.add(world);

// ---------------------------------------------------------------- Gelände
const waterColor = new THREE.Color(0.10, 0.36, 0.72);
function terrainColor(h, out) {
  const stops = [
    [150, [0.40, 0.58, 0.28]], [450, [0.30, 0.48, 0.22]], [900, [0.20, 0.36, 0.20]],
    [1200, [0.45, 0.42, 0.34]], [1500, [0.85, 0.85, 0.85]],
  ];
  if (h <= stops[0][0]) return out.set(...stops[0][1]);
  for (let i = 1; i < stops.length; i++) {
    if (h <= stops[i][0]) {
      const [h0, c0] = stops[i - 1], [h1, c1] = stops[i];
      const t = (h - h0) / (h1 - h0);
      return out.set(c0[0] + (c1[0] - c0[0]) * t, c0[1] + (c1[1] - c0[1]) * t, c0[2] + (c1[2] - c0[2]) * t);
    }
  }
  return out.set(...stops.at(-1)[1]);
}

(function buildTerrain() {
  const { nx, ny, res, x0, y0, heights } = terrain;
  const pos = new Float32Array(nx * ny * 3), col = new Float32Array(nx * ny * 3);
  const c = new THREE.Color();
  for (let j = 0; j < ny; j++) for (let i = 0; i < nx; i++) {
    const k = j * nx + i, h = heights[k];
    pos.set([x0 + i * res, h, -(y0 + j * res)], k * 3);
    terrainColor(h, c);
    // Wasseranteil der Zelle (Ziffer 0..9): teilweise Wasserzellen ergeben weiche Uferlinien und schmale Flussläufe
    const wf = terrain.water ? +terrain.water[k] / 9 : 0;
    if (wf > 0) c.lerp(waterColor, Math.min(1, wf * 1.25));
    col.set([c.r, c.g, c.b], k * 3);
  }
  const idx = [];
  for (let j = 0; j < ny - 1; j++) for (let i = 0; i < nx - 1; i++) {
    const a = j * nx + i, b = a + 1, d = a + nx, e = d + 1;
    idx.push(a, b, d, b, e, d); // gegen den Uhrzeigersinn von oben
  }
  const g = new THREE.BufferGeometry();
  g.setAttribute('position', new THREE.BufferAttribute(pos, 3));
  g.setAttribute('color', new THREE.BufferAttribute(col, 3));
  g.setIndex(idx);
  g.computeVertexNormals();
  world.add(new THREE.Mesh(g, new THREE.MeshLambertMaterial({ vertexColors: true })));

})();

// ---------------------------------------------------------------- Wolken (Oberseiten je Schicht)
const { nx: cx, ny: cy, nz, cell, half_size: hs } = clouds;
const per = cx * cy;
// Horizontale Feinunterteilung: Quellzellen (1 km) werden bilinear auf SUB x SUB Unterzellen interpoliert
// (?sub=1 schaltet das ab, ?sub=4 ist feiner).
const SUB = Math.max(1, Math.min(6, +new URLSearchParams(location.search).get('sub') || 3));
const gx = cx * SUB, gy = cy * SUB, subCell = cell / SUB;
const vx = gx + 1, vy = gy + 1, vper = vx * vy; // Gitterpunkte je Schicht
const vertCount = nz * vper;

// Bilinear-Gewichte je Gitterpunkt: 4 Quellindizes (innerhalb einer Schicht) + Gewichte
const wIdx = new Uint16Array(vper * 4), wVal = new Float32Array(vper * 4);
for (let j = 0; j < vy; j++) for (let i = 0; i < vx; i++) {
  const u = Math.min(cx - 1, Math.max(0, i / SUB - 0.5)), v = Math.min(cy - 1, Math.max(0, j / SUB - 0.5));
  const i0 = Math.min(cx - 2, Math.floor(u)), j0 = Math.min(cy - 2, Math.floor(v));
  const fx = u - i0, fy = v - j0, o = (j * vx + i) * 4;
  wIdx.set([j0 * cx + i0, j0 * cx + i0 + 1, (j0 + 1) * cx + i0, (j0 + 1) * cx + i0 + 1], o);
  wVal.set([(1 - fx) * (1 - fy), fx * (1 - fy), (1 - fx) * fy, fx * fy], o);
}
function upsample(src, base) { // src: flaches Array, base: Offset der Schicht -> Wert je Gitterpunkt
  const out = new Float32Array(vper);
  for (let c = 0; c < vper; c++) {
    const o = c * 4;
    out[c] = src[base + wIdx[o]] * wVal[o] + src[base + wIdx[o + 1]] * wVal[o + 1]
      + src[base + wIdx[o + 2]] * wVal[o + 2] + src[base + wIdx[o + 3]] * wVal[o + 3];
  }
  return out;
}

// Pro Schicht eine durchgehende Fläche auf der Schichtobergrenze; Deckkraft je Gitterpunkt, dazwischen linear.
// Einheitlich weiß, ohne Beleuchtung, keine Nähte zwischen Zellen.
const alphaAttr = new THREE.BufferAttribute(new Float32Array(vertCount), 1);
alphaAttr.setUsage(THREE.DynamicDrawUsage);
const cloudUniforms = { uOpacity: { value: 0.6 } };
const cloudMat = new THREE.ShaderMaterial({
  transparent: true, depthWrite: false, side: THREE.DoubleSide,
  uniforms: cloudUniforms,
  vertexShader: `
    attribute float aAlpha; varying float vAlpha;
    void main() {
      vAlpha = aAlpha;
      gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0);
    }`,
  fragmentShader: `
    uniform float uOpacity; varying float vAlpha;
    void main() {
      if (vAlpha < 0.01) discard;
      gl_FragColor = vec4(1.0, 1.0, 1.0, vAlpha * uOpacity);
    }`,
});

const cloudPos = new Float32Array(vertCount * 3);
for (let l = 0; l < nz; l++) {
  const top = upsample(clouds.heights, (l + 1) * per);
  for (let j = 0; j < vy; j++) for (let i = 0; i < vx; i++) {
    const c = j * vx + i;
    cloudPos.set([-hs + i * subCell, top[c], -(-hs + j * subCell)], (l * vper + c) * 3);
  }
}
const cloudIdx = new Uint32Array(nz * gx * gy * 6);
let q = 0;
for (let l = 0; l < nz; l++) for (let j = 0; j < gy; j++) for (let i = 0; i < gx; i++) {
  const a = l * vper + j * vx + i, b = a + 1, d = a + vx, e = d + 1;
  cloudIdx.set([a, b, d, b, e, d], q);
  q += 6;
}
const cloudGeo = new THREE.BufferGeometry();
cloudGeo.setAttribute('position', new THREE.BufferAttribute(cloudPos, 3));
cloudGeo.setAttribute('aAlpha', alphaAttr);
cloudGeo.setIndex(new THREE.BufferAttribute(cloudIdx, 1));
const cloudMesh = new THREE.Mesh(cloudGeo, cloudMat);
cloudMesh.frustumCulled = false;
cloudMesh.renderOrder = 2;
world.add(cloudMesh);

function showStep(t) {
  const f = clouds.clc[t], a = alphaAttr.array;
  for (let l = 0; l < nz; l++) {
    const layer = upsample(f, l * per);
    for (let c = 0; c < vper; c++) {
      const v = layer[c] / 100;
      a[l * vper + c] = v < 0.05 ? 0 : v;
    }
  }
  alphaAttr.needsUpdate = true;
}

// ---------------------------------------------------------------- Wetter-Oberfläche (2D): Temperatur, Regen, Schnee
// surface.t2m (°C), .precip (mm in der Stunde vor dem Zeitschritt), .snow (% Schneeanteil), je Zeitschritt ny*nx Werte
const surf = clouds.surface;
let updateWeather = () => {};   // aktualisiert Zahlen/Partikel für Zeitschritt t
let layoutTemps = () => {};     // Temperaturzahlen an Überhöhung anpassen
let precipDensity = () => 0;    // mm/h -> Anteil sichtbarer Partikel
let precipUniforms = null;
const wxClass = { rain: null, snow: null, temps: [] };

// Bilinear aus einem 1-km-Feld (ny*nx) an lokalen Koordinaten x (Ost), y (Nord) in m
function cellSample(f, x, y) {
  const u = Math.min(cx - 1.001, Math.max(0, (x + hs) / cell - 0.5)), v = Math.min(cy - 1.001, Math.max(0, (y + hs) / cell - 0.5));
  const i = Math.floor(u), j = Math.floor(v), a = u - i, b = v - j, o = j * cx + i;
  return f[o] * (1 - a) * (1 - b) + f[o + 1] * a * (1 - b) + f[o + cx] * (1 - a) * b + f[o + cx + 1] * a * b;
}

if (surf) {
  // ---- Temperaturzahlen: Sprites auf einem 6x6-Raster (ICON-D2 löst ~2 km auf), von Gelände verdeckt
  const TN = 6, tstep = 2 * hs / TN;
  const tempGroup = new THREE.Group();
  tempGroup.visible = false;
  scene.add(tempGroup); // nicht in `world`: Sprites sollen durch die Überhöhung nicht verzerrt werden
  for (let j = 0; j < TN; j++) for (let i = 0; i < TN; i++) {
    const x = -hs + (i + 0.5) * tstep, y = -hs + (j + 0.5) * tstep;
    const cv = document.createElement('canvas');
    cv.width = 128; cv.height = 64;
    const tex = new THREE.CanvasTexture(cv);
    tex.colorSpace = THREE.SRGBColorSpace;
    const sp = new THREE.Sprite(new THREE.SpriteMaterial({ map: tex, transparent: true, depthWrite: false }));
    sp.scale.set(1500, 750, 1);
    sp.renderOrder = 4;
    tempGroup.add(sp);
    wxClass.temps.push({ x, y, h: terrainAt(x, y), sp, cv, tex });
  }
  layoutTemps = () => {
    const ex = +$('exag').value;
    for (const t of wxClass.temps) t.sp.position.set(t.x, t.h * ex + 450, -t.y);
  };
  const drawTemps = step => {
    for (const t of wxClass.temps) {
      const v = cellSample(surf.t2m[step], t.x, t.y), g = t.cv.getContext('2d');
      g.clearRect(0, 0, 128, 64);
      g.font = '700 42px system-ui, sans-serif';
      g.textAlign = 'center'; g.textBaseline = 'middle';
      g.lineWidth = 7; g.lineJoin = 'round';
      g.strokeStyle = 'rgba(15,25,40,.85)';
      const txt = `${Math.round(v)}°`;
      g.strokeText(txt, 64, 34);
      g.fillStyle = `hsl(${Math.round(220 - Math.max(0, Math.min(1, (v + 10) / 40)) * 220)} 90% 72%)`; // -10 °C blau … 30 °C rot
      g.fillText(txt, 64, 34);
      t.tex.needsUpdate = true;
    }
  };

  // ---- Regen (Linien) und Schnee (Punkte): fallen von der Wolkenobergrenze (max. 4 km) bis zum Gelände
  precipDensity = mm => mm < 0.03 ? 0 : Math.min(1, Math.sqrt(mm / 4)); // 0,1 mm/h -> 16 %, 1 mm/h -> 50 %, 4 mm/h -> 100 %
  precipUniforms = { uTime: { value: 0 }, uPx: { value: 800 } };
  function makeParticles(n, perVertex, material, Obj) {
    const m = n * perVertex, A = () => new Float32Array(m);
    const a = { pos: new Float32Array(m * 3), ground: A(), top: A().fill(2500), den: A(), rnd: A(), phase: A(), end: A() };
    const px = new Float32Array(n), py = new Float32Array(n);
    for (let p = 0; p < n; p++) {
      const x = (Math.random() * 2 - 1) * hs, y = (Math.random() * 2 - 1) * hs, g = terrainAt(x, y);
      const r = Math.random(), ph = Math.random();
      px[p] = x; py[p] = y;
      for (let v = 0; v < perVertex; v++) {
        const o = p * perVertex + v;
        a.pos.set([x, g, -y], o * 3);
        a.ground[o] = g; a.rnd[o] = r; a.phase[o] = ph; a.end[o] = v;
      }
    }
    const geo = new THREE.BufferGeometry();
    geo.setAttribute('position', new THREE.BufferAttribute(a.pos, 3));
    for (const [k, name] of [['ground', 'aGround'], ['top', 'aTop'], ['den', 'aDen'], ['rnd', 'aRand'], ['phase', 'aPhase'], ['end', 'aEnd']]) {
      const at = new THREE.BufferAttribute(a[k], 1);
      if (k === 'top' || k === 'den') at.setUsage(THREE.DynamicDrawUsage);
      geo.setAttribute(name, at);
    }
    const obj = new Obj(geo, material);
    obj.frustumCulled = false;
    obj.renderOrder = 3;
    obj.visible = false;
    world.add(obj);
    return { obj, geo, n, pv: perVertex, x: px, y: py, a };
  }
  const common = `
    uniform float uTime;
    attribute float aGround, aTop, aDen, aRand, aPhase, aEnd;
    varying float vA;
    float fall(float speed) { return fract(aPhase + uTime * speed / aTop); } // 0 oben ... 1 am Boden
  `;
  const rain = makeParticles(50000, 2, new THREE.ShaderMaterial({
    transparent: true, depthWrite: false, uniforms: precipUniforms,
    vertexShader: common + `
      void main() {
        float f = fall(700.0);
        float y = max(aGround + aTop * (1.0 - f) + aEnd * 260.0, aGround);
        vA = step(aRand, aDen) * smoothstep(0.0, 0.12, f);
        gl_Position = vA > 0.0 ? projectionMatrix * modelViewMatrix * vec4(position.x, y, position.z, 1.0) : vec4(2.0, 2.0, 2.0, 1.0);
      }`,
    fragmentShader: `varying float vA; void main() { gl_FragColor = vec4(0.72, 0.84, 1.0, vA * 0.55); }`,
  }), THREE.LineSegments);
  const snow = makeParticles(40000, 1, new THREE.ShaderMaterial({
    transparent: true, depthWrite: false, uniforms: precipUniforms,
    vertexShader: common + `
      uniform float uPx;
      void main() {
        float f = fall(160.0);
        vec3 p = vec3(position.x + sin(uTime * 0.9 + aPhase * 40.0) * 70.0, aGround + aTop * (1.0 - f), position.z + cos(uTime * 0.7 + aPhase * 31.0) * 70.0);
        vec4 mv = modelViewMatrix * vec4(p, 1.0);
        vA = step(aRand, aDen) * smoothstep(0.0, 0.12, f);
        gl_Position = vA > 0.0 ? projectionMatrix * mv : vec4(2.0, 2.0, 2.0, 1.0);
        gl_PointSize = clamp(110.0 * uPx / -mv.z, 1.5, 7.0);
      }`,
    fragmentShader: `varying float vA; void main() {
      float d = length(gl_PointCoord - 0.5);
      gl_FragColor = vec4(1.0, 1.0, 1.0, vA * smoothstep(0.5, 0.15, d) * 0.9);
    }`,
  }), THREE.Points);
  wxClass.rain = rain; wxClass.snow = snow;

  // Höhe der Oberkante der obersten Schicht mit >= 30 % Bedeckung je Säule (NaN = keine Wolke)
  function cloudTops(step) {
    const f = clouds.clc[step], out = new Float32Array(per).fill(NaN);
    for (let c = 0; c < per; c++) for (let l = nz - 1; l >= 0; l--) {
      if (f[l * per + c] >= 30) { out[c] = clouds.heights[(l + 1) * per + c]; break; }
    }
    return out;
  }
  function updatePrecip(step) {
    const R = surf.precip[step], S = surf.snow[step], tops = cloudTops(step);
    for (const sys of [rain, snow]) {
      const isSnow = sys === snow, { a, pv } = sys;
      for (let p = 0; p < sys.n; p++) {
        const x = sys.x[p], y = sys.y[p];
        const d = precipDensity(cellSample(R, x, y)) * (isSnow ? cellSample(S, x, y) / 100 : 1 - cellSample(S, x, y) / 100);
        const col = Math.min(cy - 1, Math.max(0, Math.floor((y + hs) / cell))) * cx + Math.min(cx - 1, Math.max(0, Math.floor((x + hs) / cell)));
        const top = Number.isNaN(tops[col]) ? 2500 : Math.max(600, Math.min(4000, tops[col] - a.ground[p * pv]));
        for (let v = 0; v < pv; v++) { a.den[p * pv + v] = d; a.top[p * pv + v] = top; }
      }
      sys.geo.attributes.aDen.needsUpdate = true;
      sys.geo.attributes.aTop.needsUpdate = true;
    }
  }
  const tempOn = () => $('showtemp').checked, precipOn = () => $('showprecip').checked;
  updateWeather = step => {
    const m = cellSample(surf.t2m[step], 0, 0), r = cellSample(surf.precip[step], 0, 0), s = cellSample(surf.snow[step], 0, 0);
    $('wx').textContent = `${place.name}: ${m.toFixed(1).replace('.', ',')} °C · ` + (r < 0.03 ? 'kein Niederschlag'
      : `${r.toFixed(1).replace('.', ',')} mm/h ${s >= 50 ? 'Schnee' : s > 10 ? 'Schneeregen' : 'Regen'}`);
    if (tempOn()) drawTemps(step);
    if (precipOn()) updatePrecip(step);
  };
  const syncVisible = () => {
    tempGroup.visible = tempOn();
    rain.obj.visible = snow.obj.visible = precipOn();
    updateWeather(step);
  };
  $('showtemp').addEventListener('change', syncVisible);
  $('showprecip').addEventListener('change', syncVisible);
  layoutTemps();
  rain.obj.visible = snow.obj.visible = precipOn();
} else {
  $('wxopts').hidden = true;
  $('wx').hidden = true;
}
// Anteil der Fläche mit Niederschlag und mittlerer Schneeanteil je Zeitschritt (für den Zeitstrahl)
function precipSummary(t) {
  if (!surf) return null;
  let d = 0, sn = 0, w = 0;
  for (let c = 0; c < per; c++) {
    const v = precipDensity(surf.precip[t][c]);
    d += v; sn += v * surf.snow[t][c]; w += v;
  }
  return { mean: d / per, snow: w ? sn / w / 100 : 0 };
}

// ---------------------------------------------------------------- Zeitstrahl
const times = clouds.times.map(s => new Date(s));
const slider = $('slider');
slider.max = times.length - 1;
const fmt = new Intl.DateTimeFormat('de-DE', { weekday: 'short', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
$('runinfo').textContent = `ICON-D2 Lauf ${new Date(clouds.run).toLocaleString('de-DE', { dateStyle: 'short', timeStyle: 'short' })}`;

// Heatmap-Streifen: mittlere Bedeckung (Anteil bewölkter Säulen) je Zeitschritt
function drawCover() {
  const c = $('cover'), r = devicePixelRatio || 1;
  c.width = c.clientWidth * r; c.height = c.clientHeight * r;
  const g = c.getContext('2d');
  g.fillStyle = 'rgba(255,255,255,.12)'; g.fillRect(0, 0, c.width, c.height);
  const n = times.length, w = c.width / n;
  clouds.clc.forEach((f, t) => {
    let sum = 0;
    for (let col = 0; col < per; col++) {
      let clear = 1;
      for (let l = 0; l < nz; l++) clear *= 1 - f[l * per + col] / 100;
      sum += 1 - clear;
    }
    const cover = sum / per;
    g.fillStyle = `rgba(255,255,255,${0.15 + 0.75 * cover})`;
    const h = c.height * (0.15 + 0.85 * cover);
    g.fillRect(t * w + 1, c.height - h, w - 2, h);
    const pr = precipSummary(t); // Niederschlag: Balken oben, blau = Regen, hellblau/weiß = Schnee
    if (pr && pr.mean > 0.01) {
      g.fillStyle = pr.snow > 0.5 ? `rgba(200,230,255,${0.3 + 0.7 * pr.mean})` : `rgba(40,120,255,${0.3 + 0.7 * pr.mean})`;
      g.fillRect(t * w + 1, 0, w - 2, 4 * r);
    }
  });
}
addEventListener('resize', drawCover);

let step = 0;
function setStep(t) {
  step = Math.max(0, Math.min(times.length - 1, t));
  slider.value = step;
  $('time').textContent = fmt.format(times[step]) + ' Uhr';
  showStep(step);
  updateWeather(step);
}
// Auch der Bedeckungsstreifen steuert die Zeit (Klick/Ziehen, Maus und Touch)
const cover = $('cover');
const seek = e => {
  const r = cover.getBoundingClientRect();
  stop();
  setStep(Math.floor((e.clientX - r.left) / r.width * times.length));
};
cover.addEventListener('pointerdown', e => { cover.setPointerCapture(e.pointerId); seek(e); });
cover.addEventListener('pointermove', e => { if (cover.hasPointerCapture(e.pointerId)) seek(e); });
slider.addEventListener('input', () => { stop(); setStep(+slider.value); });

let timer = null;
function stop() { clearInterval(timer); timer = null; $('play').textContent = '▶'; }
function play() {
  if (step >= times.length - 1) setStep(0);
  $('play').textContent = '⏸';
  timer = setInterval(() => { if (step >= times.length - 1) stop(); else setStep(step + 1); }, 500);
}
$('play').addEventListener('click', () => timer ? stop() : play());
addEventListener('keydown', e => {
  if (e.code === 'Space') { e.preventDefault(); timer ? stop() : play(); }
  else if (e.code === 'ArrowRight') { stop(); setStep(step + 1); }
  else if (e.code === 'ArrowLeft') { stop(); setStep(step - 1); }
});

// Startzeit: aktuellster Zeitschritt <= jetzt
const now = Date.now();
let start = 0;
times.forEach((d, i) => { if (d.getTime() <= now) start = i; });
drawCover();
const tParam = new URLSearchParams(location.search).get('t'); // ?t=<Zeitschritt> überschreibt die Startzeit
setStep(tParam !== null ? +tParam : start);

// ---------------------------------------------------------------- Optionen
function applyExag() { world.scale.y = +$('exag').value; layoutTemps(); }
$('exag').addEventListener('input', applyExag);
$('opacity').addEventListener('input', () => { cloudUniforms.uOpacity.value = +$('opacity').value; });
$('showclouds').addEventListener('change', e => { cloudMesh.visible = e.target.checked; });
applyExag();

// ---------------------------------------------------------------- Render
// Marker: Lat/Lon -> lokale Meter, Höhe aus dem Geländemodell
const M_LAT = 111132, M_LON = 111320 * Math.cos(clouds.center.lat * Math.PI / 180);
function terrainAt(x, y) {
  const { nx, ny, res, x0, y0, heights } = terrain;
  const fx = Math.min(nx - 1.001, Math.max(0, (x - x0) / res)), fy = Math.min(ny - 1.001, Math.max(0, (y - y0) / res));
  const i = Math.floor(fx), j = Math.floor(fy), ax = fx - i, ay = fy - j, h = (jj, ii) => heights[jj * nx + ii];
  return h(j, i) * (1 - ax) * (1 - ay) + h(j, i + 1) * ax * (1 - ay) + h(j + 1, i) * (1 - ax) * ay + h(j + 1, i + 1) * ax * ay;
}
// Talachse aus dem Gelände: Richtung der geringsten Krümmung (Eigenvektor der Hesse-Matrix, geglättet).
// Winkel in Grad gegen Ost, gegen den Uhrzeigersinn (Norden = 90).
function valleyAngle(x, y, d = 600) {
  const S = (px, py) => { let a = 0; for (const i of [-1, 0, 1]) for (const j of [-1, 0, 1]) a += terrainAt(px + i * d / 2, py + j * d / 2); return a / 9; };
  const h0 = S(x, y);
  const hxx = S(x + d, y) - 2 * h0 + S(x - d, y), hyy = S(x, y + d) - 2 * h0 + S(x, y - d);
  const hxy = (S(x + d, y + d) - S(x + d, y - d) - S(x - d, y + d) + S(x - d, y - d)) / 4;
  return (0.5 * Math.atan2(2 * hxy, hxx - hyy) * 180 / Math.PI + 90 + 180) % 180; // senkrecht zur stärksten Krümmung
}
const markerDefs = [
  { name: 'Schauinsland', lat: 47.91188, lon: 7.89866 },
  { name: 'Schönberg', lat: 47.95471, lon: 7.80550 },
  // Täler: nur dezente Beschriftung, ohne Stange
  { name: 'Dreisamtal', lat: 47.97414, lon: 7.94035, valley: true },
  { name: 'Hexental', lat: 47.93650, lon: 7.79900, valley: true },
  { name: 'Glottertal', lat: 48.04853, lon: 7.95440, valley: true },
  // Höllental: Talmündung bei Himmelreich liegt knapp außerhalb des Gebiets, daher der stadtnahe Teil
  { name: 'Höllental', lat: 47.9595, lon: 7.9820, valley: true },
  { name: 'Günterstal', lat: 47.95830, lon: 7.86620, valley: true },
  // Katzental: Lage laut Google Maps zwischen Au und Horben, Position hier geschätzt (Mitte zwischen beiden Orten)
  { name: 'Katzental', lat: 47.94450, lon: 7.84000, valley: true },
  { name: 'Kappler Tal', lat: 47.97030, lon: 7.91224, valley: true },
  // Freiburger Bucht: flache Ebene, Krümmung unbrauchbar -> fester Winkel entlang des Schwarzwaldrands
  { name: 'Freiburger Bucht', lat: 48.01364, lon: 7.75433, valley: true, angle: 70 },
];
const poleMat = new THREE.MeshBasicMaterial({ color: 0xff3b30 });
// Marker außerhalb des Kartenbereichs werden nicht angezeigt; der gewählte Ort steht immer in der Mitte
const inArea = m => Math.abs((m.lon - clouds.center.lon) * M_LON) <= clouds.half_size
  && Math.abs((m.lat - clouds.center.lat) * M_LAT) <= clouds.half_size;
const allMarkers = [{ name: place.name, lat: clouds.center.lat, lon: clouds.center.lon }, ...markerDefs.filter(inArea)];
const markers = allMarkers.map(m => {
  const x = (m.lon - clouds.center.lon) * M_LON, y = (m.lat - clouds.center.lat) * M_LAT;
  const h = terrainAt(x, y);
  const el = document.createElement('div');
  document.body.appendChild(el);
  if (m.valley) {
    el.className = 'label valley';
    el.textContent = m.name;
    const a = (m.angle ?? valleyAngle(x, y)) * Math.PI / 180;
    return { el, pos: new THREE.Vector3(x, h + 150, -y), dir: new THREE.Vector3(Math.cos(a), 0, -Math.sin(a)) };
  }
  const pole = new THREE.Mesh(new THREE.CylinderGeometry(25, 25, 1, 8), poleMat);
  pole.scale.y = 1000;
  pole.position.set(x, h + 500, -y);
  world.add(pole);
  el.className = 'label';
  el.textContent = `${m.name} · ${Math.round(h)} m`;
  return { el, pos: new THREE.Vector3(x, h + 1000, -y) };
});
const tmp = new THREE.Vector3(), tmp2 = new THREE.Vector3();

function resize() {
  const w = innerWidth, h = innerHeight;
  renderer.setSize(w, h, false);
  camera.aspect = w / h;
  camera.updateProjectionMatrix();
  if (precipUniforms) precipUniforms.uPx.value = renderer.domElement.height / (2 * Math.tan(camera.fov * Math.PI / 360));
}
addEventListener('resize', resize);
resize();

const needle = $('needle'), nlabel = $('nlabel');
renderer.setAnimationLoop(() => {
  controls.update();
  if (precipUniforms) precipUniforms.uTime.value = performance.now() / 1000;
  const az = (controls.getAzimuthalAngle() * 180 / Math.PI).toFixed(1); // Norden relativ zur Blickrichtung
  needle.style.transform = `rotate(${az}deg)`;
  nlabel.setAttribute('transform', `rotate(${-az} 0 -16.5)`); // Buchstabe bleibt aufrecht
  for (const m of markers) {
    tmp.copy(m.pos).applyMatrix4(world.matrixWorld).project(camera);
    m.el.style.display = tmp.z < 1 ? '' : 'none';
    m.el.style.left = (tmp.x * 0.5 + 0.5) * innerWidth + 'px';
    m.el.style.top = (-tmp.y * 0.5 + 0.5) * innerHeight + 'px';
    if (m.dir) { // Talnamen entlang des Talverlaufs drehen (Bildschirmwinkel der Talachse), immer lesbar
      tmp2.copy(m.pos).addScaledVector(m.dir, 500).applyMatrix4(world.matrixWorld).project(camera);
      let deg = Math.atan2(-(tmp2.y - tmp.y) * innerHeight, (tmp2.x - tmp.x) * innerWidth) * 180 / Math.PI;
      if (deg > 90) deg -= 180; else if (deg < -90) deg += 180;
      m.el.style.transform = `translate(-50%, -50%) rotate(${deg.toFixed(1)}deg)`;
    }
  }
  renderer.render(scene, camera);
});

// ---------------------------------------------------------------- Update-Status
// Der Server liefert zunächst den alten Lauf und lädt einen neuen im Hintergrund; das zeigen wir an.
const upd = $('update');
const shownRun = clouds.run.replace(/[-:TZ]/g, '').slice(0, 10); // YYYYMMDDHH
// ?simulate=update: spielt den Ablauf "Update läuft -> neuer Lauf verfügbar" ohne Server nach (ca. 15 s)
const simStart = new URLSearchParams(location.search).get('simulate') === 'update' ? Date.now() : null;
async function fetchStatus() {
  if (simStart === null) return (await fetch('api/status.php', { cache: 'no-store' })).json();
  const p = Math.min(1, (Date.now() - simStart) / 15000);
  return p < 1 ? { state: 'running', run: 'sim', done: Math.round(p * 1400), total: 1400 } : { state: 'idle', run: 'sim' };
}
async function pollStatus() {
  let delay = 60000;
  try {
    const st = await fetchStatus();
    if (st.state === 'running') {
      delay = simStart === null ? 3000 : 500;
      upd.hidden = false;
      upd.replaceChildren(`Neuer Modelllauf wird geladen … ${st.done} / ${st.total}`);
      const bar = document.createElement('progress');
      bar.max = st.total || 1; bar.value = st.done || 0;
      upd.append(bar);
    } else if (st.state === 'error') {
      upd.hidden = false;
      upd.textContent = 'Aktualisierung fehlgeschlagen: ' + st.message;
    } else if (st.run && st.run !== shownRun) {
      upd.hidden = false;
      const btn = document.createElement('button');
      btn.textContent = 'Neu laden';
      btn.onclick = () => location.reload();
      upd.replaceChildren('Neuer Modelllauf verfügbar ', btn);
      return; // fertig, kein weiteres Polling nötig
    } else {
      upd.hidden = true;
    }
  } catch (e) { /* Netzfehler: später erneut */ }
  setTimeout(pollStatus, delay);
}
pollStatus();
