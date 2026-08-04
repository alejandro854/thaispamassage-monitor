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
$LOGO        = 'https://thaispamassage.es/wp-content/uploads/2022/06/logo-thaispamassage.png';
$TOKEN       = 'tsm_dorica_9f3k7q2x';
$STATE_FILE  = __DIR__ . '/.tsm-uptime-state.json';   // estado + última lectura por URL
$STATS_FILE  = __DIR__ . '/.tsm-uptime-stats.json';   // acumulado de la semana + registro de alertas
// -----------------------------------------------------------------

// Seguridad: por web exige ?key=TOKEN; por cron CLI no hace falta.
if (PHP_SAPI !== 'cli' && (($_GET['key'] ?? '') !== $TOKEN)) { http_response_code(403); exit('forbidden'); }
ignore_user_abort(true);   // si el cron por URL corta la conexión (web saturada), el script termina igual y envía la alerta
@set_time_limit(300);

$DRY     = (PHP_SAPI !== 'cli' && (($_GET['dry'] ?? '') === '1'));      // comprueba pero NO envía ni guarda
$WEEKLY  = (PHP_SAPI !== 'cli' && (($_GET['weekly'] ?? '') === '1'));   // fuerza el informe semanal (prueba)
$PANEL   = (PHP_SAPI !== 'cli' && (($_GET['panel'] ?? '') === '1'));    // devuelve JSON para el panel de WordPress

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
  // 'down' (incidencia para estadística/uptime) = fallo REAL según la política, igual que los avisos:
  // 5xx siempre; sin respuesta solo cuenta en páginas core (checkout/fichas lentos no son caídas).
  return ['bad' => $bad, 'grave' => $grave, 'down' => $down, 'detail' => $detail];
}

function sendMail($to, $subject, $html, $from) {
  $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: {$from}\r\n";
  @mail(implode(',', $to), '=?UTF-8?B?' . base64_encode($subject) . '?=', $html, $headers);
}

// Plantilla base de email (segura para todos los clientes: tablas + estilos inline + logo Thai).
function tsm_shell($preheader, $label, $title, $sub, $accent, $body) {
  $logo = 'https://thaispamassage.es/wp-content/uploads/2022/06/logo-thaispamassage.png';
  return
    '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#f2ede3;font-size:1px;line-height:1px;">' . htmlspecialchars($preheader) . '</div>'
  . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f2ede3;margin:0;padding:28px 12px;font-family:Helvetica,Arial,sans-serif;">'
  . '<tr><td align="center">'
  . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;background-color:#ffffff;border-radius:18px;overflow:hidden;box-shadow:0 10px 34px rgba(30,25,15,.14);">'
  . '<tr><td align="center" style="background-color:#201c16;background-image:linear-gradient(135deg,#2b261d,#16130e);padding:32px 32px 26px;">'
  . '<img src="' . $logo . '" alt="Thai Spa Massage" width="140" style="width:140px;max-width:55%;height:auto;display:block;margin:0 auto 16px;">'
  . '<div style="color:#c9a86a;font-size:11px;letter-spacing:3px;text-transform:uppercase;font-weight:bold;">' . htmlspecialchars($label) . '</div>'
  . '<div style="width:34px;height:2px;background-color:#c9a86a;line-height:2px;font-size:0;margin:13px auto;">&nbsp;</div>'
  . '<div style="color:#ffffff;font-family:Georgia,\'Times New Roman\',serif;font-size:24px;line-height:1.25;">' . htmlspecialchars($title) . '</div>'
  . ($sub ? '<div style="color:#a99e8a;font-size:13px;margin-top:8px;">' . htmlspecialchars($sub) . '</div>' : '')
  . '</td></tr>'
  . '<tr><td style="height:4px;background-color:' . $accent . ';line-height:4px;font-size:0;">&nbsp;</td></tr>'
  . '<tr><td style="padding:28px 32px;color:#3a352c;font-size:14px;line-height:1.55;">' . $body . '</td></tr>'
  . '<tr><td style="background-color:#faf7f0;border-top:1px solid #ece5d6;padding:22px 32px;text-align:center;">'
  . '<div style="color:#8a8272;font-size:12px;line-height:1.6;">Monitorización automática de <b style="color:#6b6456;">thaispamassage.es</b><br>Comprobación cada 5&nbsp;minutos desde servidor propio · sin servicios externos</div>'
  . '<div style="margin-top:12px;"><a href="https://dorica.agency" style="color:#c9a86a;font-size:11px;letter-spacing:1px;text-transform:uppercase;text-decoration:none;">dorica.agency</a></div>'
  . '</td></tr>'
  . '</table>'
  . '<div style="color:#b3ab99;font-size:11px;margin-top:14px;">Aviso automático para el equipo responsable de la web.</div>'
  . '</td></tr></table>';
}

// Fila de página dentro de una tabla de email (nombre + ruta + detalle con color).
function tsm_row($name, $url, $detail, $color, $dot) {
  $path = htmlspecialchars(str_replace('https://thaispamassage.es', '', $url));
  return '<tr>'
    . '<td width="58%" style="padding:13px 4px;border-top:1px solid #f0ebe0;vertical-align:top;word-break:break-word;">'
    . '<span style="color:' . $color . ';font-size:15px;">' . $dot . '</span> '
    . '<b style="color:#2a2620;font-size:14px;">' . htmlspecialchars($name) . '</b>'
    . '<div style="color:#a49a86;font-size:11px;margin:2px 0 0 18px;">' . ($path === '' ? '/' : $path) . '</div></td>'
    . '<td width="42%" align="right" style="padding:13px 4px;border-top:1px solid #f0ebe0;vertical-align:top;color:' . $color . ';font-size:13px;font-weight:bold;word-break:break-word;">' . htmlspecialchars($detail) . '</td>'
    . '</tr>';
}

function alertHtml($rows, $ok) {
  if ($ok) {
    $accent = '#2f7d54';
    $label  = 'Estado de la web';
    $title  = 'Todo ha vuelto a la normalidad';
    $intro  = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
            . '<td style="background-color:#eaf5ee;border:1px solid #cfe6d6;border-radius:12px;padding:16px 18px;color:#256b45;font-size:14px;line-height:1.5;">'
            . '&#10004;&nbsp; <b>La incidencia se ha resuelto.</b> La web ha vuelto a responder con normalidad.</td></tr></table>';
    $head   = 'Páginas recuperadas';
    $dot    = '&#10004;';
  } else {
    $accent = '#c0392b';
    $label  = 'Aviso de disponibilidad';
    $title  = 'La web necesita atención';
    $intro  = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
            . '<td style="background-color:#fdecea;border:1px solid #f5c6c1;border-radius:12px;padding:16px 18px;color:#a5352b;font-size:14px;line-height:1.5;">'
            . '&#9888;&nbsp; <b>Se ha detectado una incidencia.</b> Estas páginas no responden con normalidad ahora mismo. El monitor volverá a avisar en cuanto se recupere.</td></tr></table>';
    $head   = 'Páginas afectadas';
    $dot    = '&#9679;';
  }
  $tbl = '<p style="margin:22px 0 4px;color:#6b6456;font-size:11px;text-transform:uppercase;letter-spacing:1.5px;font-weight:bold;">' . $head . '</p>'
       . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;table-layout:fixed;">';
  foreach ($rows as $r) $tbl .= tsm_row($r['name'], $r['url'], $r['detail'], $accent, $dot);
  $tbl .= '</table>';
  return tsm_shell(($ok ? 'La web ha vuelto a la normalidad' : 'Incidencia detectada en la web'),
    $label, $title, date('d/m/Y · H:i') . ' h', $accent, $intro . $tbl);
}

// Una tarjeta KPI (celda de una fila de 3).
function tsm_kpi($value, $label, $color) {
  return '<td width="33%" align="center" style="padding:0 5px;" valign="top">'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#faf7f0;border:1px solid #efe8d8;border-radius:14px;">'
    . '<tr><td align="center" style="padding:18px 8px;">'
    . '<div style="font-family:Georgia,serif;font-size:30px;font-weight:bold;line-height:1;color:' . $color . ';">' . $value . '</div>'
    . '<div style="color:#9a927f;font-size:10.5px;text-transform:uppercase;letter-spacing:.8px;margin-top:7px;">' . htmlspecialchars($label) . '</div>'
    . '</td></tr></table></td>';
}

// Tabla de páginas del informe (cabecera + filas con velocidad media coloreada).
function tsm_week_table($rows) {
  $speedColor = function ($s) { return $s <= 1.5 ? '#2f7d54' : ($s <= 4 ? '#c07c1e' : '#c0392b'); };
  $h = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;table-layout:fixed;font-size:13px;">'
     . '<tr style="color:#a49a86;font-size:10px;text-transform:uppercase;letter-spacing:.6px;">'
     . '<td width="58%" style="padding:0 4px 8px;">Página</td>'
     . '<td width="13%" align="center" style="padding:0 4px 8px;">Veces</td>'
     . '<td width="17%" align="center" style="padding:0 4px 8px;">Velocidad</td>'
     . '<td width="12%" align="center" style="padding:0 4px 8px;">Inci.</td></tr>';
  foreach ($rows as $u) {
    $ns    = max(1, $u['nspeed'] ?? max(1, $u['count'] - $u['down']));
    $avgT  = round($u['total_sum'] / $ns, 2);
    $cat   = $u['cat'] ? '<span style="color:#b99a5b;font-size:11px;"> · ' . htmlspecialchars($u['cat']) . '</span>' : '';
    $h .= '<tr>'
      . '<td width="58%" style="padding:11px 4px;border-top:1px solid #f0ebe0;word-break:break-word;"><b style="color:#2a2620;">' . htmlspecialchars($u['name']) . '</b>' . $cat . '</td>'
      . '<td width="13%" align="center" style="padding:11px 4px;border-top:1px solid #f0ebe0;color:#6b6456;font-weight:bold;">' . intval($u['count']) . '</td>'
      . '<td width="17%" align="center" style="padding:11px 4px;border-top:1px solid #f0ebe0;color:' . $speedColor($avgT) . ';font-weight:bold;">' . $avgT . 's</td>'
      . '<td width="12%" align="center" style="padding:11px 4px;border-top:1px solid #f0ebe0;color:' . ($u['down'] ? '#c0392b' : '#c9c1af') . ';font-weight:bold;">' . intval($u['down']) . '</td></tr>';
  }
  return $h . '</table>';
}

function weeklyHtml($from, $to, $urls) {
  uasort($urls, function ($a, $b) { return strcmp(($a['cat'] ?? '') . $a['name'], ($b['cat'] ?? '') . $b['name']); });
  $checks = 0; $inc = 0;
  foreach ($urls as $u) { $checks += $u['count']; $inc += $u['down']; }
  $uptime = $checks ? round(100 * ($checks - $inc) / $checks, 2) : 100;
  $upcolor = $uptime >= 99.5 ? '#2f7d54' : ($uptime >= 98 ? '#c07c1e' : '#c0392b');

  $fixed = array_filter($urls, function ($u) { return empty($u['cat']); });
  $fichas = array_filter($urls, function ($u) { return !empty($u['cat']); });

  $body = '<p style="margin:0 0 20px;color:#6b6456;font-size:14px;line-height:1.55;">Resumen de disponibilidad y velocidad de la web durante la última semana. Todo se comprueba de forma automática cada 5&nbsp;minutos.</p>'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:8px;"><tr>'
    . tsm_kpi(number_format($uptime, 2) . '<span style="font-size:16px;">%</span>', 'Disponibilidad', $upcolor)
    . tsm_kpi($checks, 'Comprobaciones', '#2a2620')
    . tsm_kpi($inc, 'Incidencias', $inc ? '#c0392b' : '#2f7d54')
    . '</tr></table>';

  if ($fixed) {
    $body .= '<p style="margin:26px 0 6px;color:#6b6456;font-size:11px;text-transform:uppercase;letter-spacing:1.5px;font-weight:bold;">Páginas principales</p>'
      . tsm_week_table($fixed);
  }
  if ($fichas) {
    $body .= '<p style="margin:28px 0 2px;color:#6b6456;font-size:11px;text-transform:uppercase;letter-spacing:1.5px;font-weight:bold;">Fichas de masaje · rotación por categoría</p>'
      . '<p style="margin:0 0 6px;color:#a49a86;font-size:12px;">En cada comprobación se revisa una ficha al azar de cada una de las 6 categorías.</p>'
      . tsm_week_table($fichas);
  }
  $body .= '<p style="margin:24px 0 0;color:#b0a894;font-size:11.5px;line-height:1.5;border-top:1px solid #f0ebe0;padding-top:14px;">'
    . '<b>Veces</b> = comprobaciones esta semana · <b>Velocidad</b> = tiempo medio de carga · el proceso de pago se mide aparte por ser más lento de forma natural.</p>';

  return tsm_shell('Disponibilidad y velocidad de la semana',
    'Informe semanal', 'Informe semanal',
    'Semana del ' . htmlspecialchars($from) . ' al ' . htmlspecialchars($to), '#b99a5b', $body);
}
// -----------------------------------------------------------------

$state = is_file($STATE_FILE) ? (json_decode(file_get_contents($STATE_FILE), true) ?: []) : [];
$stats = is_file($STATS_FILE) ? (json_decode(file_get_contents($STATS_FILE), true) ?: []) : [];
$today = date('Y-m-d');
if (!isset($stats['urls'])) $stats = ['lastRun' => null, 'lastReport' => '', 'weekStart' => $today, 'urls' => [], 'alertsLog' => [], 'daily' => []];

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
    $ns = max(1, $u['nspeed'] ?? max(1, $u['count'] - $u['down'])); $checks += $u['count']; $inc += $u['down'];
    $week[] = ['name' => $u['name'], 'cat' => $u['cat'], 'url' => $url, 'count' => $u['count'], 'down' => $u['down'],
      'avg_ttfb' => round($u['ttfb_sum'] / $ns, 2), 'avg_total' => round($u['total_sum'] / $ns, 2)];
  }
  $daily = [];
  $dd = $stats['daily'] ?? []; ksort($dd);
  foreach (array_slice($dd, -14, null, true) as $date => $b) {
    $n = max(1, $b['n']);
    $daily[] = ['date' => $date,
      'uptime'    => $b['checks'] ? round(100 * ($b['checks'] - $b['inc']) / $b['checks'], 2) : 100,
      'avg_total' => round($b['total_sum'] / $n, 2), 'checks' => $b['checks'], 'inc' => $b['inc']];
  }
  echo json_encode([
    'updated'    => $stats['lastRun'] ?? null,
    'week_start' => $stats['weekStart'] ?? null,
    'summary'    => ['checks' => $checks, 'incidencias' => $inc, 'uptime' => $checks ? round(100 * ($checks - $inc) / $checks, 2) : 100],
    'current'    => $current,
    'week'       => $week,
    'daily'      => $daily,
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

  // Estadística de la semana. 'down' = incidencia real (política); la velocidad solo suma
  // cuando hubo respuesta válida (2xx/3xx) — un timeout no infla la media ni cuenta como caída.
  $measured = ($r['code'] >= 200 && $r['code'] < 400);
  $s = $stats['urls'][$url] ?? ['name' => $t['name'], 'cat' => $t['cat'], 'count' => 0, 'down' => 0, 'nspeed' => 0, 'ttfb_sum' => 0, 'total_sum' => 0];
  $s['name'] = $t['name']; $s['cat'] = $t['cat']; $s['count']++;
  if ($ev['down']) $s['down']++;
  if ($measured) { $s['ttfb_sum'] += $r['ttfb']; $s['total_sum'] += $r['total']; $s['nspeed'] = ($s['nspeed'] ?? 0) + 1; }
  $stats['urls'][$url] = $s;

  // Serie diaria (30 días) para el gráfico de tendencia del panel. NO se resetea con el informe semanal.
  $day = substr($now, 0, 10);
  $db = $stats['daily'][$day] ?? ['checks' => 0, 'inc' => 0, 'ttfb_sum' => 0, 'total_sum' => 0, 'n' => 0];
  $db['checks']++;
  if ($ev['down']) $db['inc']++;
  if ($measured) { $db['ttfb_sum'] += $r['ttfb']; $db['total_sum'] += $r['total']; $db['n']++; }
  $stats['daily'][$day] = $db;

  $report[] = sprintf('%-24s HTTP %d · TTFB %ss · %s', $t['name'], $r['code'], $r['ttfb'], $ev['detail']);
}

if ($DRY) { echo "DIAGNÓSTICO (no envía ni guarda):\n" . implode("\n", $report) . "\n"; exit; }

$stats['lastRun'] = $now;
$stats['alertsLog'] = array_slice($stats['alertsLog'], -40);
if (!empty($stats['daily'])) {                       // conservar solo los últimos 30 días
  ksort($stats['daily']);
  $stats['daily'] = array_slice($stats['daily'], -30, null, true);
}
@file_put_contents($STATE_FILE, json_encode($state));
@file_put_contents($STATS_FILE, json_encode($stats));

if ($alerts)     sendMail($RECIPIENTS, 'Thai Spa Massage — AVISO: web caída o lenta (' . count($alerts) . ')', alertHtml($alerts, false), $FROM);
if ($recoveries) sendMail($RECIPIENTS, 'Thai Spa Massage — Recuperado', alertHtml($recoveries, true), $FROM);

echo 'ok ' . $now . ' | comprobadas:' . count($targets) . ' avisos:' . count($alerts) . ' recuperados:' . count($recoveries) . "\n";
