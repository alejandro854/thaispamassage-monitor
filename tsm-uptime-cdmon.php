<?php
/**
 * Monitor dorica — thaispamassage.es (fuente única, sin depender de externos).
 * Corre por cron cada 5 min (por URL) en el servidor de dorica (cdmon).
 *  - Comprueba páginas FIJAS + 1 ficha AL AZAR de cada una de las 6 categorías.
 *  - Avisa por email de CAÍDA / error 500 / servidor MUY LENTO (solo páginas core).
 *  - Antes de avisar, RE-COMPRUEBA una vez (mata picos transitorios).
 *  - Acumula estadística por URL y envía un INFORME SEMANAL automático cada
 *    VIERNES por la mañana (veces revisada + velocidad media + incidencias).
 *  - ?panel=1 devuelve JSON que consume el panel de WordPress (estado + semana).
 * Reversible: borrar el archivo y la tarea de cron.
 */

// ------------------------- CONFIGURACIÓN -------------------------
$BASE  = 'https://thaispamassage.es/';

// Páginas fijas. pol=core -> vigila caída Y lentitud. pol=checkout -> solo error 5xx real.
$FIXED = [
  ['name' => 'Inicio',              'url' => 'https://thaispamassage.es/',                    'pol' => 'core'],
  ['name' => 'Masaje en Barcelona', 'url' => 'https://thaispamassage.es/masaje-en-barcelona/','pol' => 'core'],
  ['name' => 'Tienda',              'url' => 'https://thaispamassage.es/tienda/',             'pol' => 'core'],
  ['name' => 'Contacto',            'url' => 'https://thaispamassage.es/contacto/',           'pol' => 'core'],
  ['name' => 'Finalizar compra',    'url' => 'https://thaispamassage.es/finalizar-compra/',   'pol' => 'checkout'],
];

// 1 ficha al azar de cada categoría en cada ejecución (rotan cada 5 min). pol=ficha -> solo error 5xx real.
$CATEGORIES = [
  'Tailandeses'   => ['masaje-tailandes','masaje-aromatico','masaje-balines','masaje-sueco','masaje-relajante-de-hierbas','masaje-lomi-lomi','masaje-cuatro-manos'],
  'Combinados'    => ['masaje-ritual','bano-y-masaje','cabeza-y-masaje-cuerpo'],
  'En pareja'     => ['masaje-en-pareja','masaje-jacuzzi-en-pareja','masaje-deluxe-parejas'],
  'Belleza'       => ['masaje-facial','masaje-body-scrub','face-spa-massage'],
  'Embarazadas'   => ['mother-thai-massage','masaje-pies-embarazadas','head-mother-massage'],
  'Masaje + menú' => ['promo/promocion-kasa','promo/promocion-thai-gracia','promocion-comida-cena'],
];

// Políticas: down0 = un timeout/sin-respuesta cuenta como caída; slow = vigila TTFB alto;
//            immediate = avisa en el mismo ciclo (si no, exige 2 lecturas malas seguidas).
$POL = [
  'core'     => ['down0' => true,  'slow' => true,  'immediate' => true],   // páginas clave, normalmente 0,15s
  'checkout' => ['down0' => false, 'slow' => false, 'immediate' => false],  // lento por naturaleza: solo 5xx (x2)
  'ficha'    => ['down0' => false, 'slow' => false, 'immediate' => false],  // rotan/frías: solo 5xx (x2), + estadística
];

$TTFB_LIMIT  = 6.0;    // s: página core lenta -> aviso (tras 2 ciclos)
$SEVERE_TTFB = 10.0;   // s: página core MUY lenta -> aviso YA (mismo ciclo)
$TIMEOUT     = 30;     // s: máximo por comprobación
$ALERT_AFTER = 2;      // lecturas malas seguidas antes de avisar (cuando no es inmediato)
$REPORT_DOW  = 5;      // día del informe semanal (1=lunes … 5=viernes)
$REPORT_HOUR = 9;      // hora a partir de la cual se envía (mañana)

$RECIPIENTS  = ['alejandro@dorica.agency', 'jordi@dorica.agency', 'javier@dorica.agency'];
$FROM        = 'Monitor Thai Spa <monitor@dorica.agency>';
$UA          = 'DoricaUptimeBot/1.0 (+https://dorica.agency)';
$TOKEN       = 'tsm_dorica_9f3k7q2x';
$STATE_FILE  = __DIR__ . '/.tsm-uptime-state.json';   // estado + última lectura por URL
$STATS_FILE  = __DIR__ . '/.tsm-uptime-stats.json';   // acumulado de la semana + registro de alertas
// -----------------------------------------------------------------

// Seguridad: por web exige ?key=TOKEN; por cron CLI no hace falta.
if (PHP_SAPI !== 'cli' && (($_GET['key'] ?? '') !== $TOKEN)) { http_response_code(403); exit('forbidden'); }
ignore_user_abort(true);   // si el cron por URL corta la conexión (web saturada), el script termina igual y envía la alerta
@set_time_limit(300);

$DRY    = (PHP_SAPI !== 'cli' && (($_GET['dry'] ?? '') === '1'));      // comprueba pero NO envía ni guarda
$WEEKLY = (PHP_SAPI !== 'cli' && (($_GET['weekly'] ?? '') === '1'));   // fuerza el informe semanal (prueba)
$PANEL  = (PHP_SAPI !== 'cli' && (($_GET['panel'] ?? '') === '1'));    // devuelve JSON para el panel de WordPress

// ------------------------- FUNCIONES -------------------------
function check($url, $timeout, $ua) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_USERAGENT => $ua, CURLOPT_SSL_VERIFYPEER => true,
  ]);
  curl_exec($ch);
  $r = ['code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'ttfb' => round((float) curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME), 2),
        'total'=> round((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME), 2)];
  curl_close($ch);
  return $r;
}

function evaluate($r, $pol, $ttfbLimit, $severe) {
  // Caída solo si error de servidor (5xx) o —para páginas core— sin respuesta (código 0).
  // Un 200 con corte de descarga NO es caída. Checkout/fichas solo alertan por 5xx real.
  $is5xx  = ($r['code'] >= 500);
  $noResp = ($r['code'] === 0);
  $down   = $is5xx || ($pol['down0'] && $noResp);
  $slow   = $pol['slow'] && ($r['ttfb'] > $ttfbLimit);
  $bad    = $down || $slow;
  $grave  = $pol['immediate'] && ($is5xx || ($pol['down0'] && $noResp) || ($pol['slow'] && $r['ttfb'] >= $severe));
  if ($is5xx)               $detail = "error HTTP {$r['code']}";
  elseif ($noResp && $down) $detail = 'no responde / caída';
  elseif ($slow)            $detail = "servidor lento: {$r['ttfb']}s en responder";
  else                      $detail = "OK ({$r['code']}, {$r['ttfb']}s)";
  return ['bad' => $bad, 'grave' => $grave, 'down' => ($is5xx || $noResp), 'detail' => $detail];
}

function sendMail($to, $subject, $html, $from) {
  $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: {$from}\r\n";
  @mail(implode(',', $to), '=?UTF-8?B?' . base64_encode($subject) . '?=', $html, $headers);
}

function alertHtml($rows, $ok) {
  $color = $ok ? '#16a34a' : '#dc2626';
  $title = $ok ? 'Recuperado' : 'Aviso de disponibilidad';
  $h = '<div style="font-family:-apple-system,Segoe UI,Arial,sans-serif;max-width:560px;margin:auto">'
     . '<h2 style="color:' . $color . ';margin:0 0 6px">' . $title . ' — Thai Spa Massage</h2>'
     . '<p style="color:#555;font-size:13px;margin:0 0 14px">' . date('d/m/Y H:i') . '</p><table style="width:100%;border-collapse:collapse;font-size:14px">';
  foreach ($rows as $r) {
    $h .= '<tr><td style="padding:8px 6px;border-top:1px solid #eee"><b>' . htmlspecialchars($r['name']) . '</b><br>'
       . '<span style="color:#777;font-size:12px">' . htmlspecialchars(str_replace('https://thaispamassage.es', '', $r['url'])) . '</span></td>'
       . '<td style="padding:8px 6px;border-top:1px solid #eee;color:' . $color . '">' . htmlspecialchars($r['detail']) . '</td></tr>';
  }
  return $h . '</table><p style="color:#999;font-size:11px;margin-top:16px">Monitor dorica.agency · comprobación cada 5 min</p></div>';
}

function weeklyHtml($from, $to, $urls) {
  uasort($urls, function ($a, $b) { return strcmp(($a['cat'] ?? '') . $a['name'], ($b['cat'] ?? '') . $b['name']); });
  $checks = 0; $inc = 0;
  foreach ($urls as $u) { $checks += $u['count']; $inc += $u['down']; }
  $uptime = $checks ? round(100 * ($checks - $inc) / $checks, 2) : 100;
  $h = '<div style="font-family:-apple-system,Segoe UI,Arial,sans-serif;max-width:640px;margin:auto">'
     . '<h2 style="color:#1c1c1c;margin:0 0 4px">Informe semanal — Thai Spa Massage</h2>'
     . '<p style="color:#666;font-size:13px;margin:0 0 4px">Disponibilidad y velocidad · semana del ' . htmlspecialchars($from) . ' al ' . htmlspecialchars($to) . '</p>'
     . '<p style="margin:0 0 16px;font-size:14px"><b>' . number_format($uptime, 2) . '%</b> disponibilidad · '
     . '<b>' . $checks . '</b> comprobaciones · <b style="color:' . ($inc ? '#dc2626' : '#16a34a') . '">' . $inc . '</b> incidencias</p>'
     . '<table style="width:100%;border-collapse:collapse;font-size:13px">'
     . '<tr style="text-align:left;color:#888;font-size:11px;text-transform:uppercase">'
     . '<th style="padding:0 6px 8px">Página</th><th style="padding:0 6px 8px;text-align:center">Revisada</th>'
     . '<th style="padding:0 6px 8px;text-align:center">Velocidad media</th><th style="padding:0 6px 8px;text-align:center">Servidor (TTFB)</th>'
     . '<th style="padding:0 6px 8px;text-align:center">Incidencias</th></tr>';
  foreach ($urls as $u) {
    $ok    = max(1, $u['count'] - $u['down']);
    $avgT  = round($u['total_sum'] / $ok, 2);
    $avgTt = round($u['ttfb_sum'] / $ok, 2);
    $cat   = $u['cat'] ? '<span style="color:#A0926A;font-size:11px"> · ' . htmlspecialchars($u['cat']) . '</span>' : '';
    $h .= '<tr><td style="padding:8px 6px;border-top:1px solid #eee"><b>' . htmlspecialchars($u['name']) . '</b>' . $cat . '<br>'
       . '<span style="color:#999;font-size:11px">' . htmlspecialchars(str_replace('https://thaispamassage.es', '', $u['url'])) . '</span></td>'
       . '<td style="padding:8px 6px;border-top:1px solid #eee;text-align:center;font-weight:700">' . intval($u['count']) . '</td>'
       . '<td style="padding:8px 6px;border-top:1px solid #eee;text-align:center">' . $avgT . 's</td>'
       . '<td style="padding:8px 6px;border-top:1px solid #eee;text-align:center;color:#777">' . $avgTt . 's</td>'
       . '<td style="padding:8px 6px;border-top:1px solid #eee;text-align:center;color:' . ($u['down'] ? '#dc2626' : '#999') . '">' . intval($u['down']) . '</td></tr>';
  }
  return $h . '</table><p style="color:#999;font-size:11px;margin-top:16px">"Revisada" = nº de comprobaciones esa semana. "Velocidad media" = tiempo medio de carga de las válidas. Monitor dorica.agency</p></div>';
}
// -----------------------------------------------------------------

$state = is_file($STATE_FILE) ? (json_decode(file_get_contents($STATE_FILE), true) ?: []) : [];
$stats = is_file($STATS_FILE) ? (json_decode(file_get_contents($STATS_FILE), true) ?: []) : [];
$today = date('Y-m-d');
if (!isset($stats['urls'])) $stats = ['lastRun' => null, 'lastReport' => '', 'weekStart' => $today, 'urls' => [], 'alertsLog' => []];

// ---------- Endpoint JSON para el panel de WordPress (no lanza comprobaciones) ----------
if ($PANEL) {
  header('Content-Type: application/json; charset=UTF-8');
  $current = []; $week = []; $checks = 0; $inc = 0;
  foreach ($state as $url => $st) {
    if (empty($st['name'])) continue;
    if (!empty($st['cat'])) continue;   // "estado actual" solo páginas fijas; las fichas rotan (van en la tabla semanal)
    $current[] = ['name' => $st['name'], 'cat' => $st['cat'] ?? null, 'url' => $url,
      'code' => $st['code'] ?? null, 'ttfb' => $st['ttfb'] ?? null, 'total' => $st['total'] ?? null,
      'alerting' => !empty($st['alerting']), 'ts' => $st['ts'] ?? null];
  }
  foreach ($stats['urls'] as $url => $u) {
    $ok = max(1, $u['count'] - $u['down']); $checks += $u['count']; $inc += $u['down'];
    $week[] = ['name' => $u['name'], 'cat' => $u['cat'], 'url' => $url, 'count' => $u['count'], 'down' => $u['down'],
      'avg_ttfb' => round($u['ttfb_sum'] / $ok, 2), 'avg_total' => round($u['total_sum'] / $ok, 2)];
  }
  echo json_encode([
    'updated'    => $stats['lastRun'] ?? null,
    'week_start' => $stats['weekStart'] ?? null,
    'summary'    => ['checks' => $checks, 'incidencias' => $inc, 'uptime' => $checks ? round(100 * ($checks - $inc) / $checks, 2) : 100],
    'current'    => $current,
    'week'       => $week,
    'alerts'     => array_slice($stats['alertsLog'] ?? [], -20),
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

header('Content-Type: text/plain; charset=UTF-8');

// ---------- Informe semanal automático (viernes por la mañana) ----------
if (!$DRY && ($stats['lastReport'] ?? '') !== $today
    && (int) date('N') === $REPORT_DOW && (int) date('G') >= $REPORT_HOUR && !empty($stats['urls'])) {
  sendMail($RECIPIENTS, 'Thai Spa Massage — Informe semanal', weeklyHtml($stats['weekStart'] ?? $today, $today, $stats['urls']), $FROM);
  $stats['lastReport'] = $today; $stats['weekStart'] = $today; $stats['urls'] = [];
}
// Forzar informe (prueba manual): ?weekly=1
if ($WEEKLY && !empty($stats['urls'])) {
  sendMail($RECIPIENTS, 'Thai Spa Massage — Informe semanal [prueba]', weeklyHtml($stats['weekStart'] ?? $today, $today, $stats['urls']), $FROM);
  exit("informe semanal de prueba enviado.\n");
}

// ---------- Objetivos: fijas + 1 ficha al azar de cada categoría ----------
$targets = [];
foreach ($FIXED as $f) $targets[] = ['name' => $f['name'], 'url' => $f['url'], 'cat' => null, 'pol' => $f['pol']];
foreach ($CATEGORIES as $cat => $slugs) {
  $slug = $slugs[array_rand($slugs)];
  $targets[] = ['name' => $slug, 'url' => $BASE . $slug . '/', 'cat' => $cat, 'pol' => 'ficha'];
}

$alerts = []; $recoveries = []; $report = []; $now = date('c');

foreach ($targets as $t) {
  $url = $t['url'];
  $pol = $POL[$t['pol']];
  $r  = check($url, $TIMEOUT, $UA);
  $ev = evaluate($r, $pol, $TTFB_LIMIT, $SEVERE_TTFB);

  // Re-chequeo: si sale mal, confirmar una vez más (mata picos transitorios) usando la 2ª lectura.
  if ($ev['bad']) { usleep(2000000); $r = check($url, $TIMEOUT, $UA); $ev = evaluate($r, $pol, $TTFB_LIMIT, $SEVERE_TTFB); }

  // Estado + última lectura por URL (para alertas y para el panel).
  $st = $state[$url] ?? ['bad' => 0, 'alerting' => false];
  $st['name'] = $t['name']; $st['cat'] = $t['cat'];
  $st['code'] = $r['code']; $st['ttfb'] = $r['ttfb']; $st['total'] = $r['total']; $st['ts'] = $now;
  $st['bad'] = $ev['bad'] ? ($st['bad'] ?? 0) + 1 : 0;
  if ($ev['bad'] && empty($st['alerting']) && ($st['bad'] >= $ALERT_AFTER || $ev['grave'])) {
    $st['alerting'] = true;  $alerts[] = ['name' => $t['name'], 'url' => $url, 'detail' => $ev['detail']];
    $stats['alertsLog'][] = ['ts' => $now, 'type' => 'alert', 'name' => $t['name'], 'detail' => $ev['detail']];
  } elseif (!$ev['bad'] && !empty($st['alerting'])) {
    $st['alerting'] = false; $recoveries[] = ['name' => $t['name'], 'url' => $url, 'detail' => $ev['detail']];
    $stats['alertsLog'][] = ['ts' => $now, 'type' => 'recovery', 'name' => $t['name'], 'detail' => $ev['detail']];
  }
  $state[$url] = $st;

  // Estadística de la semana. Solo sumamos velocidad de mediciones válidas.
  $s = $stats['urls'][$url] ?? ['name' => $t['name'], 'cat' => $t['cat'], 'count' => 0, 'down' => 0, 'ttfb_sum' => 0, 'total_sum' => 0];
  $s['name'] = $t['name']; $s['cat'] = $t['cat']; $s['count']++;
  if ($ev['down']) $s['down']++;
  else { $s['ttfb_sum'] += $r['ttfb']; $s['total_sum'] += $r['total']; }
  $stats['urls'][$url] = $s;

  $report[] = sprintf('%-24s HTTP %d · TTFB %ss · %s', $t['name'], $r['code'], $r['ttfb'], $ev['detail']);
}

if ($DRY) { echo "DIAGNÓSTICO (no envía ni guarda):\n" . implode("\n", $report) . "\n"; exit; }

$stats['lastRun'] = $now;
$stats['alertsLog'] = array_slice($stats['alertsLog'], -40);
@file_put_contents($STATE_FILE, json_encode($state));
@file_put_contents($STATS_FILE, json_encode($stats));

if ($alerts)     sendMail($RECIPIENTS, 'Thai Spa Massage — AVISO: web caída o lenta (' . count($alerts) . ')', alertHtml($alerts, false), $FROM);
if ($recoveries) sendMail($RECIPIENTS, 'Thai Spa Massage — Recuperado', alertHtml($recoveries, true), $FROM);

echo 'ok ' . $now . ' | comprobadas:' . count($targets) . ' avisos:' . count($alerts) . ' recuperados:' . count($recoveries) . "\n";
