// Ortsauswahl: Combobox mit Open-Meteo-Geocoding-Suche + aktueller Standort.
// Der Ort steht in der URL (?lat=&lon=&name=) und wird zusätzlich im localStorage gemerkt.
const DEFAULT = { lat: 47.9959, lon: 7.8522, name: 'Freiburg (Münster)' };
const KEY = 'inversion.place';
const GEO = 'https://geocoding-api.open-meteo.com/v1/search';

export function currentPlace() {
  const q = new URLSearchParams(location.search);
  if (q.has('lat') && q.has('lon') && isFinite(+q.get('lat')) && isFinite(+q.get('lon'))) {
    const lat = +q.get('lat'), lon = +q.get('lon');
    return { lat, lon, name: q.get('name') || `${lat.toFixed(3)}, ${lon.toFixed(3)}` };
  }
  try {
    const s = JSON.parse(localStorage.getItem(KEY));
    if (s && isFinite(s.lat) && isFinite(s.lon)) return s;
  } catch { /* kein localStorage */ }
  return DEFAULT;
}

function goTo(p) {
  try { localStorage.setItem(KEY, JSON.stringify(p)); } catch { /* ignorieren */ }
  const q = new URLSearchParams(location.search);
  q.set('lat', p.lat.toFixed(4));
  q.set('lon', p.lon.toFixed(4));
  q.set('name', p.name);
  q.delete('t'); // Startzeit gilt nur für den bisherigen Ort/Lauf
  location.search = q.toString(); // lädt die Seite neu
}

export function initPlaceSearch(place) {
  const input = document.getElementById('placeinput');
  const list = document.getElementById('placelist');
  const msg = document.getElementById('placemsg');
  let items = [], active = -1, timer = null, seq = 0;
  input.value = place.name;

  const close = () => { list.hidden = true; input.setAttribute('aria-expanded', 'false'); active = -1; };
  const setActive = i => {
    active = i;
    [...list.children].forEach((li, n) => li.classList.toggle('active', n === i));
  };
  const say = text => { msg.textContent = text; msg.hidden = !text; };

  function render() {
    list.replaceChildren(...items.map((r, n) => {
      const li = document.createElement('li');
      li.setAttribute('role', 'option');
      const detail = [r.admin1, r.country].filter(Boolean).join(', ');
      li.append(r.name);
      if (detail) { const s = document.createElement('small'); s.textContent = detail; li.append(s); }
      li.addEventListener('pointerdown', e => { e.preventDefault(); choose(n); });
      return li;
    }));
    list.hidden = items.length === 0;
    input.setAttribute('aria-expanded', String(items.length > 0));
    active = -1;
  }

  function choose(n) {
    const r = items[n];
    if (!r) return;
    const detail = [r.admin1].filter(Boolean).join(', ');
    goTo({ lat: r.latitude, lon: r.longitude, name: detail && detail !== r.name ? `${r.name}, ${detail}` : r.name });
  }

  async function search(text) {
    const mine = ++seq;
    try {
      const r = await fetch(`${GEO}?name=${encodeURIComponent(text)}&count=8&language=de&format=json`);
      const j = await r.json();
      if (mine !== seq) return; // veraltete Antwort
      items = j.results || [];
      say(items.length ? '' : 'Kein Ort gefunden');
      render();
    } catch {
      say('Suche nicht erreichbar');
    }
  }

  input.addEventListener('focus', () => input.select());
  input.addEventListener('input', () => {
    clearTimeout(timer);
    const text = input.value.trim();
    if (text.length < 2) { items = []; render(); say(''); return; }
    timer = setTimeout(() => search(text), 250);
  });
  input.addEventListener('keydown', e => {
    if (e.key === 'ArrowDown') { e.preventDefault(); setActive(Math.min(items.length - 1, active + 1)); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(Math.max(0, active - 1)); }
    else if (e.key === 'Enter') { e.preventDefault(); choose(active >= 0 ? active : 0); }
    else if (e.key === 'Escape') { close(); input.value = place.name; input.blur(); }
    e.stopPropagation(); // Leertaste/Pfeile steuern sonst den Zeitstrahl
  });
  input.addEventListener('blur', () => setTimeout(() => { close(); say(''); }, 150));

  document.getElementById('locate').addEventListener('click', () => {
    if (!navigator.geolocation) { say('Standortbestimmung nicht verfügbar'); return; }
    say('Standort wird bestimmt …');
    navigator.geolocation.getCurrentPosition(
      pos => goTo({ lat: pos.coords.latitude, lon: pos.coords.longitude, name: 'Aktueller Standort' }),
      err => say(err.code === 1 ? 'Standortzugriff verweigert' : 'Standort nicht ermittelbar'),
      { enableHighAccuracy: false, timeout: 15000, maximumAge: 300000 });
  });
}
