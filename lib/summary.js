// ============================================================================
//  Genera data/summary.json — estructura compacta que consume el panel de
//  WordPress (estado actual + series recientes + medias diarias + alertas).
//  Se recalcula en cada ejecución a partir de una serie acotada (30 días).
// ============================================================================
import fs from 'node:fs';
import path from 'node:path';
import { PAGES, PROFILES } from '../config.js';

const FFS = ['mobile', 'desktop'];
export const SUMMARY_FILE = 'data/summary.json';
const KEEP_DAYS = 30;

const idOf = (pgKey, ff) => `${pgKey}:${ff}`;

export function updateSummary(byPage, { alerting = [], events = [], ts, targets = [] }) {
  let s;
  try { s = JSON.parse(fs.readFileSync(SUMMARY_FILE, 'utf8')); } catch { s = null; }
  if (!s || !Array.isArray(s.series)) {
    s = { pages: [], profiles: {}, series: [], daily: [], alertsLog: [], current: {}, alerting: [], updated: null };
  }
  s.pages = PAGES.map(p => ({ key: p.key, name: p.name, url: p.url }));
  s.profiles = { mobile: PROFILES.mobile.label, desktop: PROFILES.desktop.label };

  // --- Punto de la serie + estado actual ---
  const point = { ts }, current = {};
  for (const pg of PAGES) for (const ff of FFS) {
    const r = byPage[pg.key]?.[ff] || { down: true };
    const v = r.down ? { down: true } : { score: r.score, lcp: r.lcp, ttfb: (r.ttfb ?? null) };
    point[idOf(pg.key, ff)] = v;
    current[idOf(pg.key, ff)] = v;
  }
  s.series.push(point);
  const cutoff = Date.parse(ts) - KEEP_DAYS * 864e5;
  s.series = s.series.filter(p => Date.parse(p.ts) >= cutoff);
  s.current = current;
  s.alerting = alerting;
  s.updated = ts;

  // --- Medias diarias (a partir de la serie) ---
  const byDay = {};
  for (const p of s.series) {
    const day = p.ts.slice(0, 10);
    byDay[day] = byDay[day] || {};
    for (const pg of PAGES) for (const ff of FFS) {
      const id = idOf(pg.key, ff), v = p[id];
      if (!v) continue;
      const b = (byDay[day][id] = byDay[day][id] || { n: 0, score: 0, lcp: 0, down: 0 });
      if (v.down) b.down++;
      else { b.n++; b.score += v.score; if (v.lcp != null) b.lcp += v.lcp; }
    }
  }
  s.daily = Object.keys(byDay).sort().map(day => {
    const row = { date: day };
    for (const id in byDay[day]) {
      const b = byDay[day][id];
      row[id] = { score: b.n ? Math.round(b.score / b.n) : null, lcp: b.n ? +(b.lcp / b.n).toFixed(2) : null, down: b.down, n: b.n };
    }
    return row;
  });

  // --- Registro por URL de las fichas de masaje (rotan cada ejecución) ---
  // Guardamos cada medición con su URL para el informe semanal (veces + medias).
  s.massageLog = s.massageLog || [];
  for (const t of (targets || []).filter(x => x.massage)) for (const ff of FFS) {
    const r = byPage[t.key]?.[ff]; if (!r) continue;
    s.massageLog.push({ ts, url: t.url, category: t.category, ff,
      score: r.down ? null : r.score, lcp: r.down ? null : (r.lcp ?? null), down: r.down ? 1 : 0 });
  }
  const mcut = Date.parse(ts) - 8 * 864e5;   // ventana 8 días
  s.massageLog = s.massageLog.filter(e => Date.parse(e.ts) >= mcut);

  // --- Log de alertas / recuperaciones ---
  for (const e of events) s.alertsLog.push({ ts, ...e });
  s.alertsLog = s.alertsLog.slice(-80);

  fs.mkdirSync(path.dirname(SUMMARY_FILE), { recursive: true });
  fs.writeFileSync(SUMMARY_FILE, JSON.stringify(s));
  return s;
}

// Resumen de los últimos 7 días para el email semanal.
export function weeklyStats() {
  let s;
  try { s = JSON.parse(fs.readFileSync(SUMMARY_FILE, 'utf8')); } catch { return null; }
  const days = s.daily.slice(-7);
  const ids = [];
  for (const pg of PAGES) for (const ff of FFS) ids.push(idOf(pg.key, ff));
  // medias globales por día (móvil / escritorio) + caídas
  const perDay = days.map(d => {
    const agg = { date: d.date, mobile: { n: 0, score: 0, down: 0 }, desktop: { n: 0, score: 0, down: 0 } };
    for (const pg of PAGES) for (const ff of FFS) {
      const v = d[idOf(pg.key, ff)]; if (!v) continue;
      if (v.score != null) { agg[ff].n++; agg[ff].score += v.score; }
      agg[ff].down += v.down || 0;
    }
    return {
      date: d.date,
      mobile: agg.mobile.n ? Math.round(agg.mobile.score / agg.mobile.n) : null,
      desktop: agg.desktop.n ? Math.round(agg.desktop.score / agg.desktop.n) : null,
      down: agg.mobile.down + agg.desktop.down,
    };
  });
  const totalRuns = days.reduce((a, d) => a + Object.values(d).reduce((x, v) => x + (v.n || 0), 0), 0);
  const totalDown = perDay.reduce((a, d) => a + d.down, 0);
  const uptime = totalRuns + totalDown > 0 ? (100 * totalRuns / (totalRuns + totalDown)) : 100;
  const alerts = (s.alertsLog || []).filter(a => Date.parse(a.ts) >= Date.now() - 7 * 864e5);

  // --- Fichas de masaje: por URL, veces escaneada + medias (últimos 7 días) ---
  const mcut = Date.now() - 7 * 864e5;
  const byUrl = {};
  for (const e of (s.massageLog || [])) {
    if (Date.parse(e.ts) < mcut) continue;
    const u = byUrl[e.url] = byUrl[e.url] || { url: e.url, category: e.category, scans: 0, mob: { n: 0, s: 0, l: 0 }, desk: { n: 0, s: 0, l: 0 } };
    if (e.ff === 'mobile') u.scans++;          // 1 por ejecución (mide móvil + escritorio)
    if (e.down) continue;
    const b = e.ff === 'mobile' ? u.mob : u.desk;
    if (e.score != null) { b.n++; b.s += e.score; if (e.lcp != null) b.l += e.lcp; }
  }
  const massages = Object.values(byUrl).map(u => ({
    url: u.url, category: u.category, scans: u.scans,
    mob:  u.mob.n  ? { score: Math.round(u.mob.s / u.mob.n),   lcp: +(u.mob.l / u.mob.n).toFixed(2) }   : null,
    desk: u.desk.n ? { score: Math.round(u.desk.s / u.desk.n), lcp: +(u.desk.l / u.desk.n).toFixed(2) } : null,
  })).sort((a, b) => a.category.localeCompare(b.category) || a.url.localeCompare(b.url));

  return { perDay, uptime: +uptime.toFixed(2), alerts, updated: s.updated, massages };
}
