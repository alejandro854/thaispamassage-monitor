// ============================================================================
//  Composición y envío de emails (diseño responsive, CSS inline para Gmail).
//  Tres tipos: 'alert' (rojo), 'recovery' (verde), 'report' (informe manual).
// ============================================================================
import nodemailer from 'nodemailer';
import { SITE_NAME, SITE_URL, PAGES, PROFILES, THRESHOLDS, SMTP, RECIPIENTS } from '../config.js';

const C = {
  ink: '#1e1e1e', gold: '#a0926a', paper: '#f4f1e4', line: '#e7e2d4',
  green: '#16a34a', greenBg: '#dcfce7', amber: '#d97706', amberBg: '#fef3c7',
  red: '#dc2626', redBg: '#fee2e2', gray: '#6b7280',
};

// --- Semáforo por métrica ---------------------------------------------------
function scoreColor(s) { return s >= 90 ? C.green : s >= 50 ? C.amber : C.red; }
// Colorea una métrica (LCP o TTFB) según su propio umbral de alerta.
function limitColor(val, limit) {
  if (val == null || limit == null) return C.gray;
  return val <= limit * 0.6 ? C.green : val < limit ? C.amber : C.red;
}
function pill(text, bg, fg) {
  return `<span style="display:inline-block;padding:3px 10px;border-radius:999px;background:${bg};color:${fg};font-size:12px;font-weight:700;letter-spacing:.02em;">${text}</span>`;
}
function metricCell(m, sec) {
  if (m.down) {
    return `<td style="padding:10px 12px;border-top:1px solid ${C.line};text-align:center;">
      ${pill('CAÍDA', C.redBg, C.red)}<div style="font-size:11px;color:${C.red};margin-top:4px;">no responde</div></td>`;
  }
  const sc = scoreColor(m.score), mc = limitColor(sec.val, sec.limit);
  return `<td style="padding:10px 12px;border-top:1px solid ${C.line};text-align:center;white-space:nowrap;">
    <span style="display:inline-block;min-width:34px;padding:4px 8px;border-radius:8px;background:${sc}1a;color:${sc};font-size:16px;font-weight:800;">${m.score}</span>
    <div style="font-size:12px;color:${mc};margin-top:5px;font-weight:600;">${sec.label} ${sec.val != null ? sec.val + 's' : '—'}</div>
  </td>`;
}

// --- Cuerpo HTML ------------------------------------------------------------
export function renderEmail({ type, byPage, timestamp, alerts = [] }) {
  const isAlert = type === 'alert', isRecovery = type === 'recovery';
  const accent = isAlert ? C.red : isRecovery ? C.green : C.gold;
  const statusPill = isAlert
    ? pill('⚠ ALERTA DE RENDIMIENTO', '#ffffff', C.red)
    : isRecovery ? pill('✓ RENDIMIENTO RECUPERADO', '#ffffff', C.green)
    : pill('INFORME', '#ffffff', C.ink);

  const secFor = (pg, ff, m) => {
    const th = (pg.thresholds && pg.thresholds[ff]) || THRESHOLDS[ff];
    return th.ttfb ? { label: 'TTFB', val: m.ttfb, limit: th.ttfb } : { label: 'LCP', val: m.lcp, limit: th.lcp };
  };
  const rows = PAGES.map((pg) => {
    const mob = byPage[pg.key]?.mobile ?? { down: true };
    const dsk = byPage[pg.key]?.desktop ?? { down: true };
    return `<tr>
      <td style="padding:10px 12px;border-top:1px solid ${C.line};">
        <div style="font-weight:700;color:${C.ink};font-size:14px;">${pg.name}</div>
        <a href="${pg.url}" style="font-size:11px;color:${C.gray};text-decoration:none;">${pg.url.replace('https://', '')}</a>
      </td>
      ${metricCell(mob, secFor(pg, 'mobile', mob))}
      ${metricCell(dsk, secFor(pg, 'desktop', dsk))}
    </tr>`;
  }).join('');

  const alertBox = alerts.length ? `
    <tr><td style="padding:16px 24px 0;">
      <div style="padding:14px 16px;background:${isRecovery ? C.greenBg : C.redBg};border-radius:10px;border:1px solid ${accent}33;">
        <div style="font-weight:800;color:${accent};font-size:13px;margin-bottom:6px;">
          ${isRecovery ? 'Ha vuelto a la normalidad:' : 'Se ha detectado lentitud sostenida en:'}</div>
        ${alerts.map(a => `<div style="font-size:13px;color:${C.ink};margin:3px 0;">• <b>${a.pageName}</b> — ${a.profileLabel}: ${a.detail}</div>`).join('')}
      </div>
    </td></tr>` : '';

  return `<!doctype html><html><body style="margin:0;background:${C.paper};font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:${C.paper};padding:24px 12px;"><tr><td align="center">
    <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#fff;border-radius:16px;overflow:hidden;border:1px solid ${C.line};">
      <!-- Cabecera -->
      <tr><td style="background:${C.ink};padding:22px 24px;border-bottom:4px solid ${accent};">
        <div style="color:${C.gold};font-size:13px;letter-spacing:.08em;text-transform:uppercase;">Monitor de velocidad</div>
        <div style="color:#fff;font-size:22px;font-weight:800;margin-top:2px;">${SITE_NAME}</div>
        <div style="margin-top:12px;">${statusPill}</div>
      </td></tr>
      ${alertBox}
      <!-- Tabla de resultados -->
      <tr><td style="padding:${alerts.length ? '4px' : '18px'} 24px 6px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
          <tr>
            <th style="text-align:left;padding:0 12px 8px;font-size:11px;color:${C.gray};text-transform:uppercase;letter-spacing:.05em;">Página</th>
            <th style="text-align:center;padding:0 12px 8px;font-size:11px;color:${C.gray};text-transform:uppercase;letter-spacing:.05em;">${PROFILES.mobile.label}</th>
            <th style="text-align:center;padding:0 12px 8px;font-size:11px;color:${C.gray};text-transform:uppercase;letter-spacing:.05em;">${PROFILES.desktop.label}</th>
          </tr>
          ${rows}
        </table>
      </td></tr>
      <!-- Leyenda -->
      <tr><td style="padding:10px 24px 4px;font-size:11px;color:${C.gray};line-height:1.6;">
        El número es la <b>puntuación de rendimiento (0-100)</b> y <b>LCP</b> es cuándo se ve el contenido principal.
        Colores: ${pill('bien', C.greenBg, C.green)} ${pill('mejorable', C.amberBg, C.amber)} ${pill('malo', C.redBg, C.red)}.
        Medido con Google Lighthouse en <b>${PROFILES.mobile.label}</b> y <b>${PROFILES.desktop.label}</b>.
      </td></tr>
      <!-- Pie -->
      <tr><td style="padding:16px 24px;border-top:1px solid ${C.line};font-size:11px;color:${C.gray};">
        ${timestamp} · Comprobación automática cada hora · <a href="${SITE_URL}" style="color:${C.gold};text-decoration:none;">${SITE_URL.replace('https://', '')}</a>
      </td></tr>
    </table>
    <div style="font-size:11px;color:${C.gray};margin-top:14px;">Monitor de velocidad — <a href="https://dorica.agency/" style="color:${C.gold};text-decoration:none;">dorica.agency</a></div>
  </td></tr></table>
  </body></html>`;
}

export function subjectFor(type, alerts) {
  if (type === 'alert') {
    const who = alerts.map(a => a.pageName).filter((v, i, s) => s.indexOf(v) === i).join(', ');
    return `⚠️ ${SITE_NAME}: lentitud detectada — ${who}`;
  }
  if (type === 'recovery') return `✅ ${SITE_NAME}: la web ha vuelto a ir rápida`;
  if (type === 'digest') return `📊 Resumen semanal de velocidad — ${SITE_NAME}`;
  return `📊 ${SITE_NAME}: informe de velocidad`;
}

// --- Email de resumen semanal (viernes) ------------------------------------
export function renderDigest({ perDay = [], uptime = 100, alerts = [], timestamp }) {
  const dayLabel = (d) => new Date(d + 'T12:00:00').toLocaleDateString('es-ES', { weekday: 'short', day: 'numeric', month: 'short' });
  const cell = (score) => {
    if (score == null) return `<td style="padding:9px 12px;border-top:1px solid ${C.line};text-align:center;color:${C.gray};">—</td>`;
    const c = scoreColor(score);
    return `<td style="padding:9px 12px;border-top:1px solid ${C.line};text-align:center;"><span style="display:inline-block;min-width:32px;padding:3px 9px;border-radius:8px;background:${c}1a;color:${c};font-size:15px;font-weight:800;">${score}</span></td>`;
  };
  const rows = perDay.map(d => `<tr>
    <td style="padding:9px 12px;border-top:1px solid ${C.line};color:${C.ink};font-weight:600;text-transform:capitalize;font-size:13px;">${dayLabel(d.date)}</td>
    ${cell(d.mobile)}${cell(d.desktop)}
    <td style="padding:9px 12px;border-top:1px solid ${C.line};text-align:center;color:${d.down ? C.red : C.gray};font-weight:${d.down ? 700 : 400};">${d.down || 0}</td>
  </tr>`).join('');
  const upC = uptime >= 99.5 ? C.green : uptime >= 98 ? C.amber : C.red;
  const incBox = alerts.length ? `
    <tr><td style="padding:6px 24px 0;">
      <div style="font-size:12px;color:${C.gray};text-transform:uppercase;letter-spacing:.05em;margin:8px 0 4px;">Incidencias de la semana</div>
      ${alerts.map(a => `<div style="font-size:13px;color:${C.ink};margin:3px 0;">${a.type === 'recovery' ? '✅' : '⚠️'} <b>${a.page}</b> (${a.profile}) — ${a.detail} <span style="color:${C.gray};">· ${new Date(a.ts).toLocaleString('es-ES', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}</span></div>`).join('')}
    </td></tr>` : `
    <tr><td style="padding:12px 24px 0;"><div style="padding:12px 14px;background:${C.greenBg};border-radius:10px;color:${C.green};font-weight:700;font-size:13px;text-align:center;">Sin incidencias esta semana 🎉 La web ha ido bien.</div></td></tr>`;

  return `<!doctype html><html><body style="margin:0;background:${C.paper};font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:${C.paper};padding:24px 12px;"><tr><td align="center">
    <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#fff;border-radius:16px;overflow:hidden;border:1px solid ${C.line};">
      <tr><td style="background:${C.ink};padding:22px 24px;border-bottom:4px solid ${C.gold};">
        <div style="color:${C.gold};font-size:13px;letter-spacing:.08em;text-transform:uppercase;">Resumen semanal de velocidad</div>
        <div style="color:#fff;font-size:22px;font-weight:800;margin-top:2px;">${SITE_NAME}</div>
      </td></tr>
      <tr><td style="padding:18px 24px 4px;">
        <table role="presentation" width="100%"><tr>
          <td style="text-align:center;padding:8px;background:${upC}12;border-radius:12px;">
            <div style="font-size:30px;font-weight:800;color:${upC};">${uptime}%</div>
            <div style="font-size:12px;color:${C.gray};">disponibilidad</div></td>
          <td style="width:12px;"></td>
          <td style="text-align:center;padding:8px;background:${C.paper};border-radius:12px;">
            <div style="font-size:30px;font-weight:800;color:${alerts.filter(a=>a.type!=='recovery').length ? C.red : C.green};">${alerts.filter(a => a.type !== 'recovery').length}</div>
            <div style="font-size:12px;color:${C.gray};">alertas esta semana</div></td>
        </tr></table>
      </td></tr>
      <tr><td style="padding:14px 24px 4px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
          <tr>
            <th style="text-align:left;padding:0 12px 8px;font-size:11px;color:${C.gray};text-transform:uppercase;letter-spacing:.05em;">Día</th>
            <th style="text-align:center;padding:0 12px 8px;font-size:11px;color:${C.gray};text-transform:uppercase;">${PROFILES.mobile.label}</th>
            <th style="text-align:center;padding:0 12px 8px;font-size:11px;color:${C.gray};text-transform:uppercase;">${PROFILES.desktop.label}</th>
            <th style="text-align:center;padding:0 12px 8px;font-size:11px;color:${C.gray};text-transform:uppercase;">Caídas</th>
          </tr>
          ${rows}
        </table>
      </td></tr>
      ${incBox}
      <tr><td style="padding:12px 24px;font-size:11px;color:${C.gray};line-height:1.6;">
        Media diaria de la <b>puntuación de rendimiento (0-100)</b> de todas las páginas. Cuanto más alto, mejor.
      </td></tr>
      <tr><td style="padding:14px 24px;border-top:1px solid ${C.line};font-size:11px;color:${C.gray};">
        ${timestamp} · Resumen automático semanal · <a href="${SITE_URL}" style="color:${C.gold};text-decoration:none;">${SITE_URL.replace('https://', '')}</a>
      </td></tr>
    </table>
    <div style="font-size:11px;color:${C.gray};margin-top:14px;">Monitor de velocidad — <a href="https://dorica.agency/" style="color:${C.gold};text-decoration:none;">dorica.agency</a></div>
  </td></tr></table></body></html>`;
}

export async function sendEmail({ subject, html, to = RECIPIENTS }) {
  if (!SMTP.host || !SMTP.user || !SMTP.pass) {
    throw new Error('Faltan credenciales SMTP (SMTP_HOST / SMTP_USER / SMTP_PASS).');
  }
  const transporter = nodemailer.createTransport({
    host: SMTP.host, port: SMTP.port, secure: SMTP.secure,
    auth: { user: SMTP.user, pass: SMTP.pass },
  });
  const info = await transporter.sendMail({
    from: `"${SMTP.fromName}" <${SMTP.from}>`,
    to: to.join(', '),
    subject, html,
  });
  return info.messageId;
}
