// ============================================================================
//  Monitor de velocidad — thaispamassage.es
//  Configuración central. Edita aquí páginas, umbrales y destinatarios.
// ============================================================================

export const SITE_NAME = 'Thai Spa Massage';
export const SITE_URL  = 'https://thaispamassage.es';

// --- Páginas FIJAS que se miden siempre ------------------------------------
export const PAGES = [
  { key: 'home',   name: 'Inicio',              url: 'https://thaispamassage.es/' },
  { key: 'regala', name: 'Elegir masaje (regalo)', url: 'https://thaispamassage.es/regala-thai-spa-massage/' },
  // Checkout: NO se cachea (por diseño), así que siempre es más lento. La señal
  // clave aquí es el TTFB (tiempo de servidor). Con el carrito vacío redirige a
  // /carrito/. Umbral propio, más alto, para alertar solo si el servidor se
  // degrada de verdad (su normal: TTFB ~2,9s, score 72-93).
  { key: 'checkout', name: 'Finalizar compra', url: 'https://thaispamassage.es/finalizar-compra/',
    thresholds: {
      mobile:  { lcp: 6.0, score: 35, ttfb: 6.0 },
      desktop: { lcp: 5.0, score: 35, ttfb: 6.0 },
    } },
];

// --- Fichas de masaje por CATEGORÍA -----------------------------------------
// Cada ejecución se mide 1 masaje AL AZAR de cada una de las 6 categorías (6 fichas).
// En el informe semanal se lista cada URL con las veces escaneada y sus medias.
export const MASSAGE_CATEGORIES = {
  'Tailandeses':       ['masaje-tailandes','masaje-aromatico','masaje-balines','masaje-sueco','masaje-relajante-de-hierbas','masaje-lomi-lomi','masaje-cuatro-manos'],
  'Combinados':        ['masaje-ritual','bano-y-masaje','cabeza-y-masaje-cuerpo'],
  'En pareja':         ['masaje-en-pareja','masaje-jacuzzi-en-pareja','masaje-deluxe-parejas'],
  'Belleza (cara y cuerpo)': ['masaje-facial','masaje-body-scrub','face-spa-massage'],
  'Embarazadas':       ['mother-thai-massage','masaje-pies-embarazadas','head-mother-massage'],
  'Masaje + menú':     ['promo/promocion-kasa','promo/promocion-thai-gracia','promocion-comida-cena'],
};

// --- Perfiles de medición ---------------------------------------------------
// Móvil "5G realista": red rápida (como pidió el cliente) + CPU de móvil de gama media.
// Así los números reflejan al usuario real, no la 4G-lenta de laboratorio.
export const PROFILES = {
  mobile: {
    label: 'Móvil (5G)',
    formFactor: 'mobile',
    screenEmulation: { mobile: true, width: 390, height: 844, deviceScaleFactor: 2, disabled: false },
    throttling: { rttMs: 28, throughputKbps: 45000, cpuSlowdownMultiplier: 1.8,
      requestLatencyMs: 28, downloadThroughputKbps: 45000, uploadThroughputKbps: 15000 },
    throttlingMethod: 'simulate',
    emulatedUserAgent: 'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36 ThaiSpaSpeedBot',
  },
  desktop: {
    label: 'Escritorio',
    formFactor: 'desktop',
    screenEmulation: { mobile: false, width: 1350, height: 940, deviceScaleFactor: 1, disabled: false },
    throttling: { rttMs: 20, throughputKbps: 60000, cpuSlowdownMultiplier: 1,
      requestLatencyMs: 0, downloadThroughputKbps: 60000, uploadThroughputKbps: 20000 },
    throttlingMethod: 'simulate',
    emulatedUserAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 ThaiSpaSpeedBot',
  },
};

// --- Umbrales de ALERTA (zona roja) ----------------------------------------
// Basados en Core Web Vitals de Google (el estándar). Alerta = rendimiento
// realmente malo, no un simple "mejorable". Medido en 5G la home va a ~99, así
// que solo salta si la web se degrada de verdad.
export const THRESHOLDS = {
  mobile:  { lcp: 4.0, score: 50, ttfb: 3.5 },   // LCP en segundos, score 0-100, ttfb en s (server lento)
  desktop: { lcp: 2.5, score: 50, ttfb: 3.0 },
};

// Nº de mediciones seguidas en rojo antes de avisar (evita falsas alarmas por
// el ruido normal de Lighthouse). Con cron horario = ~2 horas malas seguidas.
export const ALERT_AFTER = 2;

// TTFB (s) a partir del cual el servidor está CLARAMENTE saturado: avisa YA,
// sin esperar a 2 lecturas seguidas (una caída o un TTFB así es una urgencia).
export const SEVERE_TTFB = 8.0;

// Nº de pasadas por medición (se coge la mediana → menos ruido).
export const RUNS_PER_CHECK = 2;

// --- Destinatarios de las alertas ------------------------------------------
// ROLLOUT PROGRESIVO: empieza solo Alejandro. Cuando valide, descomenta Javier.
// Cuando Javier valide, descomenta el cliente.
export const RECIPIENTS = [
  'alejandro@dorica.agency',
  'jordi@dorica.agency',
  'javier@dorica.agency',
  // 'kiapapa2000@gmail.com',     // añadir cuando el equipo valide (cliente)
];

// Marca en el User-Agent para que el bot NO cuente como visita real
// (excluir en GA4 por este texto). Ver README.
export const BOT_UA_MARKER = 'ThaiSpaSpeedBot';

// --- SMTP (se leen de variables de entorno / GitHub Secrets) ---------------
export const SMTP = {
  host: process.env.SMTP_HOST,
  port: Number(process.env.SMTP_PORT || 587),
  secure: String(process.env.SMTP_SECURE || 'false') === 'true', // true si puerto 465
  user: process.env.SMTP_USER,
  pass: process.env.SMTP_PASS,
  from: process.env.SMTP_FROM || process.env.SMTP_USER,
  fromName: 'Monitor Thai Spa Massage',
};

export const HISTORY_FILE = 'data/history.json'; // estado + últimas mediciones
export const CSV_FILE      = 'data/history.csv';  // registro completo ("datos, no opiniones")
