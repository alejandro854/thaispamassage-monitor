// ============================================================================
//  Runner de Lighthouse — mide una URL con un perfil (móvil/escritorio).
//  - Chrome nuevo y limpio en cada pasada.
//  - Varias pasadas → se coge la MEJOR (menos ruido de Lighthouse).
//  - Si la página falla/cae → devuelve { down: true, error }.
// ============================================================================
import * as chromeLauncher from 'chrome-launcher';
import lighthouse from 'lighthouse';
import { PROFILES, RUNS_PER_CHECK } from '../config.js';

const CHROME_FLAGS = ['--headless=new', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage'];

async function onePass(url, profileKey) {
  const p = PROFILES[profileKey];
  const chrome = await chromeLauncher.launch({ chromeFlags: CHROME_FLAGS });
  try {
    const settings = {
      onlyCategories: ['performance'],
      formFactor: p.formFactor,
      screenEmulation: p.screenEmulation,
      throttling: p.throttling,
      throttlingMethod: p.throttlingMethod,
      emulatedUserAgent: p.emulatedUserAgent,
      maxWaitForLoad: 60000,
    };
    const { lhr } = await lighthouse(
      url,
      { port: chrome.port, output: 'json', logLevel: 'error' },
      { extends: 'lighthouse:default', settings }
    );
    if (lhr.runtimeError) throw new Error(lhr.runtimeError.message);
    const a = lhr.audits;
    const g = (id) => (a[id] && typeof a[id].numericValue === 'number' ? a[id].numericValue : null);
    return {
      score: Math.round((lhr.categories.performance.score ?? 0) * 100),
      lcp:  g('largest-contentful-paint') != null ? +(g('largest-contentful-paint') / 1000).toFixed(2) : null,
      fcp:  g('first-contentful-paint')   != null ? +(g('first-contentful-paint')   / 1000).toFixed(2) : null,
      tbt:  g('total-blocking-time')      != null ? Math.round(g('total-blocking-time')) : null,
      cls:  g('cumulative-layout-shift')  != null ? +g('cumulative-layout-shift').toFixed(3) : null,
      si:   g('speed-index')              != null ? +(g('speed-index') / 1000).toFixed(2) : null,
      ttfb: g('server-response-time')     != null ? +(g('server-response-time') / 1000).toFixed(2) : null,
      finalUrl: lhr.finalDisplayedUrl || url,
    };
  } finally {
    try { await chrome.kill(); } catch { /* kill() puede devolver void según versión */ }
  }
}

// Mide una URL con un perfil. Devuelve la mejor de N pasadas, o marca caída.
export async function measure(url, profileKey) {
  const passes = [];
  let lastError = null;
  for (let i = 0; i < RUNS_PER_CHECK; i++) {
    try {
      passes.push(await onePass(url, profileKey));
    } catch (e) {
      lastError = e;
    }
  }
  if (passes.length === 0) {
    return { down: true, error: (lastError && lastError.message) ? lastError.message.slice(0, 200) : 'La página no cargó' };
  }
  // "mejor" pasada = mayor score; desempate por menor LCP
  passes.sort((x, y) => (y.score - x.score) || ((x.lcp ?? 99) - (y.lcp ?? 99)));
  return { down: false, ...passes[0], runs: passes.length };
}
