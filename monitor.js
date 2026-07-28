// ============================================================================
//  Monitor de velocidad — thaispamassage.es
//  Mide las páginas, decide si hay que alertar (degradación SOSTENIDA),
//  envía emails y guarda historial. Pensado para correr cada hora (GitHub Actions).
//
//  Uso:
//    node monitor.js            → medición real + alertas por email
//    node monitor.js --preview  → genera emails de ejemplo (HTML) sin enviar
//    node monitor.js --report   → fuerza envío de informe con el estado actual
// ============================================================================
import fs from 'node:fs';
import path from 'node:path';
import { PAGES, PROFILES, THRESHOLDS, ALERT_AFTER, HISTORY_FILE, CSV_FILE } from './config.js';
import { measure } from './lib/lighthouse.js';
import { renderEmail, subjectFor, sendEmail } from './lib/email.js';

const FFS = ['mobile', 'desktop'];
const nowISO = () => new Date().toISOString();
const stamp = () => new Date().toLocaleString('es-ES', { dateStyle: 'long', timeStyle: 'short', timeZone: 'Europe/Madrid' });

// --- Estado / historial -----------------------------------------------------
function loadHistory() {
  try { return JSON.parse(fs.readFileSync(HISTORY_FILE, 'utf8')); }
  catch { return { targets: {}, lastRun: null }; }
}
function saveHistory(h) {
  fs.mkdirSync(path.dirname(HISTORY_FILE), { recursive: true });
  fs.writeFileSync(HISTORY_FILE, JSON.stringify(h, null, 2));
}
function appendCSV(rows) {
  fs.mkdirSync(path.dirname(CSV_FILE), { recursive: true });
  if (!fs.existsSync(CSV_FILE)) fs.writeFileSync(CSV_FILE, 'timestamp,pagina,dispositivo,score,lcp_s,fcp_s,tbt_ms,cls,speedindex_s,ttfb_s,caida\n');
  fs.appendFileSync(CSV_FILE, rows.map(r => r.join(',')).join('\n') + '\n');
}

// --- Evaluación: ¿está mal este resultado? ----------------------------------
function evaluate(res, ff, page) {
  if (res.down) return { bad: true, detail: 'no responde / error de carga' };
  const t = (page && page.thresholds && page.thresholds[ff]) || THRESHOLDS[ff];
  if (res.score < t.score) return { bad: true, detail: `puntuación ${res.score} (límite ${t.score})` };
  if (t.ttfb && res.ttfb != null && res.ttfb > t.ttfb) return { bad: true, detail: `servidor lento ${res.ttfb}s (límite ${t.ttfb}s)` };
  if (res.lcp != null && res.lcp > t.lcp) return { bad: true, detail: `LCP ${res.lcp}s (límite ${t.lcp}s)` };
  return { bad: false, detail: `${res.score} · LCP ${res.lcp}s` };
}

// --- Medición de todas las páginas ------------------------------------------
async function measureAll() {
  const byPage = {};
  for (const pg of PAGES) {
    byPage[pg.key] = {};
    for (const ff of FFS) {
      process.stderr.write(`  midiendo ${pg.name} · ${PROFILES[ff].label}... `);
      const res = await measure(pg.url, ff);
      byPage[pg.key][ff] = res;
      process.stderr.write(res.down ? 'CAÍDA\n' : `score ${res.score}, LCP ${res.lcp}s\n`);
    }
  }
  return byPage;
}

// --- Ejecución principal ----------------------------------------------------
async function run({ report = false } = {}) {
  const byPage = await measureAll();
  const h = loadHistory();
  h.targets = h.targets || {};

  const newAlerts = [], recoveries = [], csv = [];
  const ts = nowISO();

  for (const pg of PAGES) {
    for (const ff of FFS) {
      const res = byPage[pg.key][ff];
      const id = `${pg.key}:${ff}`;
      const st = h.targets[id] || { consecutiveBad: 0, alerting: false };
      const ev = evaluate(res, ff, pg);

      st.consecutiveBad = ev.bad ? st.consecutiveBad + 1 : 0;

      if (ev.bad && st.consecutiveBad >= ALERT_AFTER && !st.alerting) {
        st.alerting = true;
        newAlerts.push({ pageName: pg.name, profileLabel: PROFILES[ff].label, detail: ev.detail });
      } else if (!ev.bad && st.alerting) {
        st.alerting = false;
        recoveries.push({ pageName: pg.name, profileLabel: PROFILES[ff].label, detail: ev.detail });
      }
      h.targets[id] = st;

      csv.push([ts, pg.name, PROFILES[ff].label,
        res.down ? '' : res.score, res.down ? '' : (res.lcp ?? ''), res.down ? '' : (res.fcp ?? ''),
        res.down ? '' : (res.tbt ?? ''), res.down ? '' : (res.cls ?? ''), res.down ? '' : (res.si ?? ''),
        res.down ? '' : (res.ttfb ?? ''), res.down ? '1' : '0']);
    }
  }

  h.lastRun = ts;
  saveHistory(h);
  appendCSV(csv);

  // --- Emails ---
  const sent = [];
  if (newAlerts.length) {
    const html = renderEmail({ type: 'alert', byPage, timestamp: stamp(), alerts: newAlerts });
    await sendEmail({ subject: subjectFor('alert', newAlerts), html });
    sent.push(`ALERTA (${newAlerts.length})`);
  }
  if (recoveries.length) {
    const html = renderEmail({ type: 'recovery', byPage, timestamp: stamp(), alerts: recoveries });
    await sendEmail({ subject: subjectFor('recovery', recoveries), html });
    sent.push(`RECUPERADO (${recoveries.length})`);
  }
  if (report) {
    const html = renderEmail({ type: 'report', byPage, timestamp: stamp(), alerts: [] });
    await sendEmail({ subject: subjectFor('report'), html });
    sent.push('INFORME');
  }

  console.log(sent.length ? `\n✉️  Emails enviados: ${sent.join(', ')}` : '\n✔ Todo correcto — sin cambios, sin email.');
}

// --- Preview: genera emails de ejemplo (HTML) sin enviar --------------------
function preview() {
  // datos realistas (calibración) + un caso simulado en rojo para ver la alerta
  const ok = (score, lcp, ttfb) => ({ down: false, score, lcp, fcp: +(lcp * 0.7).toFixed(2), tbt: 0, cls: 0, si: lcp, ttfb, runs: 2 });
  const goodByPage = {
    home:     { mobile: ok(99, 1.85), desktop: ok(90, 1.37) },
    masaje:   { mobile: ok(98, 2.39), desktop: ok(86, 1.74) },
    regala:   { mobile: ok(99, 0.92), desktop: ok(97, 0.71) },
    checkout: { mobile: ok(93, 0.89, 2.9), desktop: ok(72, 1.0, 2.86) },
  };
  const alertByPage = {
    home:     { mobile: { down: false, score: 38, lcp: 6.1, fcp: 3.2, tbt: 900, cls: 0.05, si: 8 }, desktop: ok(88, 1.5) },
    masaje:   { mobile: ok(97, 2.4), desktop: ok(85, 1.8) },
    regala:   { mobile: { down: true }, desktop: ok(96, 0.8) },
    checkout: { mobile: ok(28, 3.5, 9.8), desktop: ok(30, 3.2, 9.5) },
  };
  const files = [
    ['preview-ok.html',    renderEmail({ type: 'report', byPage: goodByPage, timestamp: stamp(), alerts: [] })],
    ['preview-alert.html', renderEmail({ type: 'alert', byPage: alertByPage, timestamp: stamp(),
      alerts: [ { pageName: 'Inicio', profileLabel: 'Móvil (5G)', detail: 'LCP 6.1s (límite 4s)' },
                { pageName: 'Elegir masaje (regalo)', profileLabel: 'Móvil (5G)', detail: 'no responde / error de carga' },
                { pageName: 'Finalizar compra', profileLabel: 'Móvil (5G)', detail: 'servidor lento 9.8s (límite 6s)' } ] })],
    ['preview-recovery.html', renderEmail({ type: 'recovery', byPage: goodByPage, timestamp: stamp(),
      alerts: [ { pageName: 'Inicio', profileLabel: 'Móvil (5G)', detail: '99 · LCP 1.85s' } ] })],
  ];
  for (const [name, html] of files) { fs.writeFileSync(name, html); console.log('  generado', name); }
}

// --- Entry point ------------------------------------------------------------
const arg = process.argv[2];
if (arg === '--preview') preview();
else run({ report: arg === '--report' }).catch(e => { console.error('ERROR:', e.message); process.exit(1); });
