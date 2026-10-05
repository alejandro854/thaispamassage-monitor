<?php
/**
 * Monitor dorica — thaispamassage.es (fuente única, sin depender de externos).
 * Corre por cron cada 5 min (por URL) en el servidor de dorica (cdmon).
 *  - Comprueba páginas FIJAS + 1 ficha AL AZAR de cada una de las 6 categorías.
 *  - Avisa por email de CAÍDA / error 500 / servidor MUY LENTO (solo páginas core).
 *  - Antes de avisar, RE-COMPRUEBA una vez (mata picos transitorios).
 *  - Acumula estadística por URL y envía un INFORME SEMANAL automático cada
 *    VIERNES por la mañana (veces revisada + velocidad media + incidencias).
 *  - COMPRA DE PRUEBA cada ~30 min: carrito → finalizar compra → pago con tarjeta visible →
 *    cálculo del pedido → «Realizar pedido». La web (mu-plugin tsm-compra-prueba.php) la corta
 *    justo ANTES de crear el pedido: no hay pedidos, ni pendientes ni fallidos, ni paso por Redsys.
 *    Si falla (dos intentos seguidos) avisa al momento: es lo más crítico de la web.
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
  'Masaje + menú' => ['promo/promocion-thai-gracia','promocion-comida-cena'],
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
$ALERT_AFTER = 3;      // lecturas malas SEGUIDAS antes de avisar (~15 min). Evita el ruido de baches breves que se recuperan solos.
$REPORT_DOW  = 5;      // día del informe semanal (1=lunes … 5=viernes)
$REPORT_HOUR = 9;      // hora a partir de la cual se envía (mañana)

$RECIPIENTS  = ['alejandro@dorica.agency', 'javier@dorica.agency', 'kiapapa2000@gmail.com']; // Alejandro + Javier + Hugo (cliente final)
$FROM        = 'Monitor Thai Spa <monitor@dorica.agency>';
$UA          = 'DoricaUptimeBot/1.0 (+https://dorica.agency)';
$LOGO        = 'https://thaispamassage.es/wp-content/uploads/2022/06/logo-thaispamassage.png';
$TOKEN       = 'tsm_dorica_9f3k7q2x';
$STATE_FILE  = __DIR__ . '/.tsm-uptime-state.json';   // estado + última lectura por URL
$STATS_FILE  = __DIR__ . '/.tsm-uptime-stats.json';   // acumulado de la semana + registro de alertas
$SYNTH_KEY   = '__TSM_SYNTH_KEY__';   // clave de la compra de prueba (la real solo está en el servidor; el repo es público)
$CSS_SAMPLE  = 5;      // nº de hojas de estilo del tema/plugins a verificar por ciclo (assets estáticos = baratísimo)
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

// Descarga el HTML de una página (devuelve código + cuerpo).
function fetchBody($url, $timeout, $ua) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_USERAGENT => $ua, CURLOPT_SSL_VERIFYPEER => true,
  ]);
  $body = curl_exec($ch);
  $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return ['code' => $code, 'body' => is_string($body) ? $body : ''];
}

// Petición HEAD (solo cabeceras): comprobar un asset estático es muy barato.
function headCode($url, $timeout, $ua) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_USERAGENT => $ua, CURLOPT_SSL_VERIFYPEER => true,
  ]);
  curl_exec($ch);
  $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return $code;
}

// Verifica que las hojas de estilo (tema/plugins) que referencia la home cargan (no 404).
// Detecta el estado "página sin CSS". Reachable=false si no pudimos leer la home (de la caída
// ya se encarga el check de uptime, no lo duplicamos aquí).
function checkAssets($base, $timeout, $ua, $sample) {
  $home = fetchBody($base, $timeout, $ua);
  if ($home['code'] < 200 || $home['code'] >= 400 || $home['body'] === '') {
    return ['reachable' => false, 'checked' => 0, 'bad' => []];
  }
  if (!preg_match_all('/<link\b[^>]*\bhref=("|\')(.*?)\1[^>]*>/i', $home['body'], $m, PREG_SET_ORDER)) {
    return ['reachable' => true, 'checked' => 0, 'bad' => []];
  }
  $urls = [];
  foreach ($m as $tag) {
    if (!preg_match('/\brel=("|\')?[^"\'>]*stylesheet/i', $tag[0])) continue;   // solo <link rel="stylesheet">
    $href = html_entity_decode($tag[2], ENT_QUOTES);
    if (strpos($href, '//') === 0)      $href = 'https:' . $href;
    elseif (isset($href[0]) && $href[0] === '/') $href = rtrim($base, '/') . $href;
    if (strpos($href, 'thaispamassage.es') === false) continue;   // solo mismo dominio
    if (strpos($href, '/wp-content/') === false)      continue;   // tema/plugins (donde ocurría el fallo)
    $urls[$href] = true;
    if (count($urls) >= $sample) break;
  }
  $bad = [];
  foreach (array_keys($urls) as $u) {
    $code = headCode($u, 15, $ua);
    if ($code !== 200) {                 // re-verifica una vez (mata blips transitorios)
      usleep(1500000);
      $code = headCode($u, 15, $ua);
      if ($code !== 200) $bad[] = ['url' => $u, 'code' => $code];
    }
  }
  return ['reachable' => true, 'checked' => count($urls), 'bad' => $bad];
}

// Compra de prueba completa con una sesión propia (cookies en memoria). Devuelve
// ['ok' => bool, 'step' => paso, 'detail' => texto para el aviso, 'secs' => duración total].
function compraPrueba($base, $key, $ua) {
  $t0 = microtime(true);
  $ch = curl_init();
  $base = rtrim($base, '/');
  $req = function ($url, $post = null, $follow = true) use ($ch, $key, $ua) {
    $opt = [
      CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => $follow,
      CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_USERAGENT => $ua,
      CURLOPT_SSL_VERIFYPEER => true, CURLOPT_COOKIEFILE => '', CURLOPT_ENCODING => '',
      CURLOPT_HTTPHEADER => ['X-TSM-Synthetic: ' . $key, 'X-Requested-With: XMLHttpRequest'],
    ];
    if ($post !== null) { $opt[CURLOPT_POST] = true; $opt[CURLOPT_POSTFIELDS] = http_build_query($post); }
    else                { $opt[CURLOPT_HTTPGET] = true; }
    curl_setopt_array($ch, $opt);
    $body = curl_exec($ch);
    return ['code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => is_string($body) ? $body : ''];
  };
  $fin = function ($ok, $step, $detail) use ($ch, $t0) {
    curl_close($ch);
    return ['ok' => $ok, 'step' => $step, 'detail' => $detail, 'secs' => round(microtime(true) - $t0, 1)];
  };

  // 1. Producto de prueba (la web elige la tarjeta regalo más barata disponible).
  $r = $req($base . '/?tsm_synth=info');
  $info = json_decode($r['body'], true);
  if ($r['code'] !== 200 || empty($info['ok'])) return $fin(false, 'Producto', 'No se pudo preparar la compra de prueba (HTTP ' . $r['code'] . ')');

  // 2. Añadir al carrito.
  $post = ['add-to-cart' => $info['product_id'], 'product_id' => $info['product_id'], 'variation_id' => $info['variation_id'], 'quantity' => 1] + (array) $info['attributes'];
  $r = $req($info['url'], $post, false);
  if ($r['code'] >= 500 || $r['code'] === 0) return $fin(false, 'Carrito', 'No se puede añadir una tarjeta regalo al carrito (HTTP ' . $r['code'] . ')');

  // 3. Finalizar compra: carga, lleva el producto y ofrece pago con tarjeta.
  $r = $req($base . '/finalizar-compra/');
  if ($r['code'] !== 200) return $fin(false, 'Finalizar compra', 'La página de pago no carga (HTTP ' . $r['code'] . ')');
  if (strpos($r['body'], 'id="payment_method_redsys"') === false) return $fin(false, 'Pago con tarjeta', 'En la página de pago no aparece el pago con tarjeta (Redsys)');
  preg_match('/"update_order_review_nonce":"([^"]+)"/', $r['body'], $m1);
  preg_match('/name="woocommerce-process-checkout-nonce" value="([^"]+)"/', $r['body'], $m2);
  if (empty($m1[1]) || empty($m2[1])) return $fin(false, 'Finalizar compra', 'La página de pago no tiene el formulario de compra (¿carrito vacío?)');

  // 4. Cálculo del pedido (lo que falló el 30-09-2026: el botón se quedaba «pensando»).
  $r = $req($base . '/?wc-ajax=update_order_review', ['security' => $m1[1], 'payment_method' => 'redsys', 'country' => 'ES', 'post_data' => 'billing_country=ES']);
  $j = json_decode($r['body'], true);
  if ($r['code'] !== 200 || ($j['result'] ?? '') !== 'success') return $fin(false, 'Cálculo del pedido', 'El cálculo del pedido falla (HTTP ' . $r['code'] . '): el pago se queda «pensando»');

  // 5. «Realizar pedido»: la web valida todo y corta antes de crear el pedido.
  $r = $req($base . '/?wc-ajax=checkout', [
    'billing_first_name' => 'Monitor', 'billing_last_name' => 'Prueba dorica', 'billing_country' => 'ES',
    'billing_phone' => '600000000', 'billing_email' => 'monitor@dorica.agency', 'billing_email_confirm' => 'monitor@dorica.agency',
    'billing_name_from' => 'Monitor', 'billing_name_to' => 'Prueba', 'payment_method' => 'redsys',
    'woocommerce-process-checkout-nonce' => $m2[1], '_wp_http_referer' => '/finalizar-compra/',
  ]);
  $j = json_decode($r['body'], true);
  if ($r['code'] !== 200 || !is_array($j)) return $fin(false, 'Realizar pedido', 'Al pulsar «Realizar pedido» la web da error (HTTP ' . $r['code'] . ')');
  if (($j['tsm_synthetic'] ?? '') !== 'ok') {
    $msg = !empty($j['errors']) ? implode(' · ', (array) $j['errors']) : trim(strip_tags($j['messages'] ?? 'respuesta inesperada'));
    return $fin(false, 'Realizar pedido', 'Al pulsar «Realizar pedido»: ' . (function_exists('mb_substr') ? mb_substr($msg, 0, 220) : substr($msg, 0, 220)));
  }
  return $fin(true, 'OK', 'Compra completa OK (' . ($j['total'] ?? '?') . ' €, pago con tarjeta disponible)');
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
  // El HTML va en una sola línea muy larga; sin codificar, el servidor de correo la parte a
  // la fuerza y puede cortar una etiqueta (aparecía un "</td>" suelto en el informe). Con
  // quoted-printable las líneas se cortan de forma segura (con "=") y el cliente las recompone.
  $headers = "MIME-Version: 1.0\r\n"
           . "Content-Type: text/html; charset=UTF-8\r\n"
           . "Content-Transfer-Encoding: quoted-printable\r\n"
           . "From: {$from}\r\n";
  $body = function_exists('quoted_printable_encode') ? quoted_printable_encode($html) : $html;
  @mail(implode(',', $to), '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
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
  . '<div style="margin-top:12px;"><a href="https://dorica.agency" style="color:#c9a86a;font-size:11px;letter-spacing:1px;text-transform:uppercase;text-decoration:none;"><img src="https://dorica.agency/logo-dorica-mail.png" alt="dorica.agency" width="65" height="18" style="width:65px;height:18px;display:inline-block;border:0;vertical-align:middle;"></a></div>'
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

// Email dedicado para el estado de los estilos (CSS). $ok=true -> recuperación.
function cssAlertHtml($badlist, $ok) {
  if ($ok) {
    $accent = '#2f7d54'; $label = 'Estado de los estilos'; $title = 'Los estilos vuelven a cargar';
    $body = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
          . '<td style="background-color:#eaf5ee;border:1px solid #cfe6d6;border-radius:12px;padding:16px 18px;color:#256b45;font-size:14px;line-height:1.5;">'
          . '&#10004;&nbsp; <b>Resuelto.</b> Las hojas de estilo vuelven a cargar correctamente; la web se ve bien.</td></tr></table>';
  } else {
    $accent = '#c0392b'; $label = 'Aviso de estilos (CSS)'; $title = 'La web podría verse sin estilos';
    $body = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
          . '<td style="background-color:#fdecea;border:1px solid #f5c6c1;border-radius:12px;padding:16px 18px;color:#a5352b;font-size:14px;line-height:1.5;">'
          . '&#9888;&nbsp; <b>Se han detectado hojas de estilo que no cargan (error 404).</b> Es probable que algunas páginas se estén viendo rotas (sin CSS). Suele resolverse limpiando la caché de WP&nbsp;Rocket.</td></tr></table>'
          . '<p style="margin:22px 0 4px;color:#6b6456;font-size:11px;text-transform:uppercase;letter-spacing:1.5px;font-weight:bold;">Archivos afectados</p>'
          . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;table-layout:fixed;">';
    foreach ((array) $badlist as $line) {
      $body .= '<tr><td style="padding:11px 4px;border-top:1px solid #f0ebe0;color:#a5352b;font-size:12px;word-break:break-all;">&#9679;&nbsp;' . htmlspecialchars($line) . '</td></tr>';
    }
    $body .= '</table>';
  }
  return tsm_shell(($ok ? 'Los estilos han vuelto' : 'La web podría verse sin estilos'),
    $label, $title, date('d/m/Y · H:i') . ' h', $accent, $body);
}

// Email de la compra de prueba. $ok=true -> recuperación.
function compraAlertHtml($res, $ok) {
  if ($ok) {
    $accent = '#2f7d54'; $label = 'Compra de prueba'; $title = 'La compra vuelve a funcionar';
    $body = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
          . '<td style="background-color:#eaf5ee;border:1px solid #cfe6d6;border-radius:12px;padding:16px 18px;color:#256b45;font-size:14px;line-height:1.5;">'
          . '&#10004;&nbsp; <b>Resuelto.</b> La compra de prueba ha llegado hasta el pago con tarjeta sin problemas. Los clientes ya pueden comprar con normalidad.</td></tr></table>';
  } else {
    $accent = '#c0392b'; $label = 'AVISO · Compra de prueba'; $title = 'Los clientes no pueden comprar';
    $body = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
          . '<td style="background-color:#fdecea;border:1px solid #f5c6c1;border-radius:12px;padding:16px 18px;color:#a5352b;font-size:14px;line-height:1.5;">'
          . '&#9888;&nbsp; <b>La compra de prueba ha fallado dos veces seguidas.</b> Es muy probable que ahora mismo los clientes no puedan comprar tarjetas ni cajas regalo en la web. Conviene revisarlo cuanto antes.</td></tr></table>'
          . '<p style="margin:22px 0 4px;color:#6b6456;font-size:11px;text-transform:uppercase;letter-spacing:1.5px;font-weight:bold;">Dónde falla</p>'
          . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;table-layout:fixed;">'
          . tsm_row($res['step'], 'https://thaispamassage.es/finalizar-compra/', $res['detail'], $accent, '&#9679;') . '</table>'
          . '<p style="margin:18px 0 0;color:#9a927f;font-size:12px;line-height:1.5;">La prueba no crea pedidos ni cobra nada: se detiene justo antes de crear el pedido.</p>';
  }
  return tsm_shell(($ok ? 'La compra vuelve a funcionar' : 'Los clientes no pueden comprar en la web'),
    $label, $title, date('d/m/Y · H:i') . ' h', $accent, $body);
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
    'compra'     => isset($state['__compra__']) ? [
      'ok'     => $state['__compra__']['ok'] ?? true,
      'step'   => $state['__compra__']['step'] ?? '',
      'detail' => $state['__compra__']['detail'] ?? '',
      'secs'   => $state['__compra__']['secs'] ?? null,
      'ts'     => $state['__compra__']['ts'] ?? null,
    ] : null,
    'css'        => isset($state['__assets__']) ? [
      'ok'      => $state['__assets__']['ok'] ?? true,
      'checked' => $state['__assets__']['checked'] ?? 0,
      'bad'     => $state['__assets__']['badlist'] ?? [],
      'ts'      => $state['__assets__']['ts'] ?? null,
    ] : null,
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
foreach ($FIXED as $f) {
  // El checkout es una página MUY pesada (17-30s de PHP, sin caché). Para no cargar el servidor,
  // lo comprobamos solo cada ~30 min (en los minutos :00 y :30), no en cada ciclo de 5 min.
  if ($f['pol'] === 'checkout' && ((int) date('i') % 30) >= 5) continue;
  $targets[] = ['name' => $f['name'], 'url' => $f['url'], 'cat' => null, 'pol' => $f['pol']];
}
// Las fichas fuerzan generación PHP (páginas sin caché). Para NO cargar Thai, se comprueban
// solo cada ~30 min (minutos :00 y :30). Cada 5 min solo se miran las páginas clave cacheadas.
if (((int) date('i') % 30) < 5) {
  foreach ($CATEGORIES as $cat => $slugs) {
    $slug = $slugs[array_rand($slugs)];
    $targets[] = ['name' => $slug, 'url' => $BASE . $slug . '/', 'cat' => $cat, 'pol' => 'ficha'];
  }
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
  if ($ev['bad'] && empty($st['alerting']) && $st['bad'] >= $ALERT_AFTER) {   // sin "aviso inmediato": el problema debe persistir
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

// ---------- Integridad de estilos (CSS): detecta "página sin CSS" ----------
// Assets estáticos (nginx los sirve sin PHP) → coste casi nulo, se puede mirar cada ciclo.
$cssAlert = null; $cssRecovery = null;
$as = checkAssets($BASE, $TIMEOUT, $UA, $CSS_SAMPLE);
if ($as['reachable']) {
  $ast  = $state['__assets__'] ?? ['bad' => 0, 'alerting' => false];
  $nbad = count($as['bad']);
  $ast['checked'] = $as['checked'];
  $ast['nbad']    = $nbad;
  $ast['badlist'] = array_slice(array_map(function ($b) { return $b['url'] . ' (' . $b['code'] . ')'; }, $as['bad']), 0, 8);
  $ast['ok']      = ($nbad === 0);
  $ast['ts']      = $now;
  $ast['bad']     = $nbad > 0 ? (($ast['bad'] ?? 0) + 1) : 0;
  if ($nbad > 0 && empty($ast['alerting']) && $ast['bad'] >= $ALERT_AFTER) {   // exige persistencia (~15 min), como el resto
    $ast['alerting'] = true; $cssAlert = $ast['badlist'];
    $stats['alertsLog'][] = ['ts' => $now, 'type' => 'alert', 'name' => 'Estilos (CSS)', 'detail' => $nbad . ' hoja(s) de estilo no cargan'];
  } elseif ($nbad === 0 && !empty($ast['alerting'])) {
    $ast['alerting'] = false; $cssRecovery = true;
    $stats['alertsLog'][] = ['ts' => $now, 'type' => 'recovery', 'name' => 'Estilos (CSS)', 'detail' => 'estilos OK'];
  }
  $state['__assets__'] = $ast;
  $report[] = sprintf('%-24s %d comprobados · %d con error', 'Estilos (CSS)', $as['checked'], $nbad);
}

// ---------- Compra de prueba (cada ~30 min, o siempre con ?compra=1 en diagnóstico) ----------
$compraAlert = null; $compraRecovery = null;
$FORCE_COMPRA = (PHP_SAPI !== 'cli' && (($_GET['compra'] ?? '') === '1'));
if ($SYNTH_KEY !== '' && strpos($SYNTH_KEY, '__') !== 0 && (((int) date('i') % 30) < 5 || $FORCE_COMPRA)) {
  $cp = compraPrueba($BASE, $SYNTH_KEY, $UA);
  if (!$cp['ok']) { sleep(20); $cp = compraPrueba($BASE, $SYNTH_KEY, $UA); }   // 2º intento: descarta un fallo puntual
  $cst = $state['__compra__'] ?? ['alerting' => false];
  $cst = array_merge($cst, ['ok' => $cp['ok'], 'step' => $cp['step'], 'detail' => $cp['detail'], 'secs' => $cp['secs'], 'ts' => $now]);
  if (!$cp['ok'] && empty($cst['alerting'])) {           // crítico: avisa ya (ya son 2 intentos fallidos)
    $cst['alerting'] = true; $compraAlert = $cp;
    $stats['alertsLog'][] = ['ts' => $now, 'type' => 'alert', 'name' => 'Compra de prueba', 'detail' => $cp['step'] . ': ' . $cp['detail']];
  } elseif ($cp['ok'] && !empty($cst['alerting'])) {
    $cst['alerting'] = false; $compraRecovery = $cp;
    $stats['alertsLog'][] = ['ts' => $now, 'type' => 'recovery', 'name' => 'Compra de prueba', 'detail' => 'compra OK'];
  }
  $state['__compra__'] = $cst;
  $report[] = sprintf('%-24s %s · %s · %ss', 'Compra de prueba', $cp['ok'] ? 'OK' : 'FALLO', $cp['step'] . ' — ' . $cp['detail'], $cp['secs']);
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
if ($cssAlert)    sendMail($RECIPIENTS, 'Thai Spa Massage — AVISO: estilos rotos (CSS no carga)', cssAlertHtml($cssAlert, false), $FROM);
if ($compraAlert)    sendMail($RECIPIENTS, 'Thai Spa Massage — URGENTE: la compra en la web no funciona', compraAlertHtml($compraAlert, false), $FROM);
if ($compraRecovery) sendMail($RECIPIENTS, 'Thai Spa Massage — La compra vuelve a funcionar', compraAlertHtml($compraRecovery, true), $FROM);
if ($cssRecovery) sendMail($RECIPIENTS, 'Thai Spa Massage — Estilos recuperados', cssAlertHtml([], true), $FROM);

echo 'ok ' . $now . ' | comprobadas:' . count($targets) . ' avisos:' . count($alerts) . ' recuperados:' . count($recoveries) . "\n";
