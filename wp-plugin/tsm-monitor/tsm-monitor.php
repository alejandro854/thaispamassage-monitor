<?php
/**
 * Plugin Name: Monitor de disponibilidad
 * Description: Panel de disponibilidad y velocidad de thaispamassage.es. Los datos los genera el Monitor dorica (servidor propio de dorica.agency, comprueba cada 5 min sin depender de servicios externos).
 * Version:     2.1.0
 * Author:      dorica.agency
 * Author URI:  https://dorica.agency/
 */
if (!defined('ABSPATH')) exit;

// Endpoint JSON del Monitor dorica (servidor propio, cada 5 min).
define('TSM_MON_PANEL_URL', 'https://dorica.agency/tsm-uptime.php?panel=1&key=tsm_dorica_9f3k7q2x');
define('TSM_MON_LOGO',      'https://thaispamassage.es/wp-content/uploads/2022/06/logo-thaispamassage.png');

add_action('admin_menu', function () {
    add_menu_page('Monitorización', 'Monitorización', 'manage_options',
        'tsm-monitor', 'tsm_mon_render_page', 'dashicons-chart-area', 58);
});

add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'toplevel_page_tsm-monitor') return;
    wp_enqueue_script('tsm-chartjs', plugins_url('chart.umd.min.js', __FILE__), array(), '4.4.4', true);
});

function tsm_mon_get_data($force = false) {
    if (!$force) { $c = get_transient('tsm_mon_panel'); if ($c) return $c; }
    $res = wp_remote_get(TSM_MON_PANEL_URL, array('timeout' => 15));
    if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) {
        return get_transient('tsm_mon_panel') ?: null;
    }
    $data = json_decode(wp_remote_retrieve_body($res), true);
    if (!is_array($data)) return get_transient('tsm_mon_panel') ?: null;
    set_transient('tsm_mon_panel', $data, 5 * MINUTE_IN_SECONDS);
    return $data;
}

// Color por velocidad de carga (segundos): verde rápido, ámbar, rojo lento.
function tsm_mon_speed_color($s) { return $s === null ? '#9ca3af' : ($s <= 1.5 ? '#16a34a' : ($s <= 4 ? '#d97706' : '#dc2626')); }

function tsm_mon_render_page() {
    if (!current_user_can('manage_options')) return;
    if (isset($_GET['refresh'])) { tsm_mon_get_data(true); }
    $d = tsm_mon_get_data();
    ?>
    <style>
    .tsm-mon{--ink:#1e1e1e;--gold:#a99367;--line:#ece7db;--green:#16a34a;--amber:#d97706;--red:#dc2626;--gray:#6b7280;
      margin:16px 20px 40px 0;color:#1f2430;font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif}
    .tsm-mon *{box-sizing:border-box}
    @keyframes tsmUp{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:none}}
    .tsm-mon .anim{animation:tsmUp .5s cubic-bezier(.22,1,.36,1) both}
    .tsm-hero{background:linear-gradient(135deg,#232019,#1a1712);border-radius:20px;padding:24px 28px;color:#fff;
      display:flex;align-items:center;gap:22px;box-shadow:0 14px 40px rgba(20,15,5,.22);flex-wrap:wrap}
    .tsm-hero img{height:56px;width:auto}
    .tsm-hero .t{flex:1;min-width:180px}
    .tsm-hero .lbl{color:var(--gold);font-size:12px;letter-spacing:.16em;text-transform:uppercase}
    .tsm-hero h1{color:#fff;font-size:26px;font-weight:800;margin:2px 0 0;padding:0}
    .tsm-hero .meta{color:#b9b2a3;font-size:12.5px;margin-top:8px}
    .tsm-status{display:inline-flex;align-items:center;gap:8px;padding:9px 16px;border-radius:999px;font-weight:700;font-size:13px}
    .tsm-status .dot{width:9px;height:9px;border-radius:50%;box-shadow:0 0 0 4px rgba(255,255,255,.12);animation:tsmPulse 2s infinite}
    @keyframes tsmPulse{0%,100%{opacity:1}50%{opacity:.45}}
    .tsm-refresh{color:#cfc7b6 !important;text-decoration:none;font-size:13px;border:1px solid rgba(255,255,255,.22);
      padding:7px 14px;border-radius:999px;transition:.2s;white-space:nowrap}
    .tsm-refresh:hover{background:rgba(255,255,255,.1);color:#fff !important}
    .tsm-kpis{display:flex;gap:14px;flex-wrap:wrap;margin:20px 0 4px}
    .tsm-kpi{background:#fff;border:1px solid var(--line);border-radius:16px;padding:16px 22px;min-width:150px;flex:1}
    .tsm-kpi .k{font-size:11px;color:#9ca3af;text-transform:uppercase;letter-spacing:.06em}
    .tsm-kpi .v{font-size:30px;font-weight:800;line-height:1.1;margin-top:4px}
    .tsm-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:16px;margin:20px 0 26px}
    .tsm-card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:16px 18px;position:relative;overflow:hidden;
      transition:transform .28s cubic-bezier(.22,1,.36,1),box-shadow .28s}
    .tsm-card:hover{transform:translateY(-4px);box-shadow:0 16px 32px rgba(30,25,15,.1)}
    .tsm-card .bar{position:absolute;left:0;top:0;bottom:0;width:4px}
    .tsm-card .name{font-weight:700;color:#1f2430;font-size:14.5px}
    .tsm-card .url{font-size:11px;color:#a3a3a3;text-decoration:none}
    .tsm-card .big{font-size:30px;font-weight:800;line-height:1;margin-top:14px}
    .tsm-card .sub{font-size:11.5px;color:#6b7280;margin-top:4px}
    .tsm-mon h2.sec{font-size:16px;margin:26px 0 10px}
    .tsm-mon .note{color:#6b7280;font-size:13px;margin:-4px 0 10px}
    .tsm-log{width:100%;max-width:920px;border-collapse:separate;border-spacing:0;background:#fff;border:1px solid var(--line);border-radius:14px;overflow:hidden}
    .tsm-log th{background:#f7f6f1;text-align:left;padding:11px 14px;font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.04em}
    .tsm-log td{padding:11px 14px;border-top:1px solid #f0eee7;font-size:13px}
    .tsm-log .cat{color:#a0926a;font-size:11px}
    .tsm-foot{margin-top:20px;color:#a3a3a3;font-size:12px}
    .tsm-foot a{color:var(--gold)}
    /* Panel de tendencia */
    .tsm-panel{background:#fff;border:1px solid var(--line);border-radius:18px;padding:20px 22px 16px;margin:22px 0 6px}
    .tsm-panelhead{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px}
    .tsm-panelhead h2{margin:0;font-size:16px}
    .tsm-seg{display:inline-flex;background:#f3f1ea;border-radius:10px;padding:3px;gap:2px}
    .tsm-seg button{border:0;background:transparent;padding:6px 15px;border-radius:8px;font-size:12.5px;font-weight:600;color:#6b7280;cursor:pointer;transition:.2s}
    .tsm-seg button.on{background:#fff;color:#1f2430;box-shadow:0 1px 3px rgba(0,0,0,.1)}
    .tsm-note{font-size:11.5px;color:#9ca3af;margin:10px 2px 0}
    </style>

    <div class="wrap tsm-mon">
    <?php
    if (!$d) {
        echo '<div class="tsm-hero anim"><div class="t"><div class="lbl">Monitorización web</div><h1>Sin datos todavía</h1>'
            . '<div class="meta">El Monitor dorica generará el primer resumen en su próxima comprobación (cada 5 min).</div></div>'
            . '<a class="tsm-refresh" href="' . esc_url(add_query_arg('refresh', 1)) . '">↻ Actualizar</a></div></div>';
        return;
    }

    $current = isset($d['current']) ? $d['current'] : array();
    $week    = isset($d['week']) ? $d['week'] : array();
    $summary = isset($d['summary']) ? $d['summary'] : array('checks' => 0, 'incidencias' => 0, 'uptime' => 100);
    $alerts  = isset($d['alerts']) ? array_reverse($d['alerts']) : array();
    $updated = isset($d['updated']) ? $d['updated'] : null;
    $anyAlerting = false;
    foreach ($current as $c) { if (!empty($c['alerting'])) $anyAlerting = true; }

    // Frescura: el cron corre cada 5 min; si hace >20 min que no mide, algo va mal.
    $stale = false; $ago = '';
    if ($updated) {
        $mins = (time() - strtotime($updated)) / 60;
        $stale = $mins > 20;
        $ago = $mins < 90 ? 'hace ' . max(1, round($mins)) . ' min' : 'hace ' . round($mins / 60, 1) . ' h';
    }
    if ($stale)             { $st = array('#f59e0b', '#fffbeb', 'El monitor lleva ' . $ago . ' sin medir — revísalo'); }
    elseif ($anyAlerting)   { $st = array('#f87171', 'rgba(220,38,38,.18)', 'Aviso activo en alguna página'); }
    else                    { $st = array('#16a34a', 'rgba(22,163,74,.16)', 'Todo funcionando correctamente'); }

    // --- HERO ---
    echo '<div class="tsm-hero anim"><img src="' . esc_url(TSM_MON_LOGO) . '" alt="Thai Spa Massage">';
    echo '<div class="t"><div class="lbl">Monitorización web</div><h1>Disponibilidad y velocidad</h1>';
    echo '<div class="meta">' . ($updated ? 'Última comprobación: ' . esc_html(wp_date('j M Y, H:i', strtotime($updated))) . ' · ' . esc_html($ago) : '') . ' · comprueba cada 5 min</div></div>';
    echo '<div class="tsm-status" style="background:' . $st[1] . ';color:' . $st[0] . ';"><span class="dot" style="background:' . $st[0] . '"></span>' . esc_html($st[2]) . '</div>';
    echo '<a class="tsm-refresh" href="' . esc_url(add_query_arg('refresh', 1)) . '">↻ Actualizar</a></div>';

    // --- KPIs de la semana ---
    $upcolor = $summary['uptime'] >= 99.5 ? '#16a34a' : ($summary['uptime'] >= 98 ? '#d97706' : '#dc2626');
    echo '<div class="tsm-kpis">';
    echo '<div class="tsm-kpi anim"><div class="k">Disponibilidad (semana)</div><div class="v" style="color:' . $upcolor . '">' . esc_html(number_format((float)$summary['uptime'], 2)) . '%</div></div>';
    echo '<div class="tsm-kpi anim" style="animation-delay:60ms"><div class="k">Comprobaciones</div><div class="v">' . intval($summary['checks']) . '</div></div>';
    echo '<div class="tsm-kpi anim" style="animation-delay:120ms"><div class="k">Incidencias</div><div class="v" style="color:' . ($summary['incidencias'] ? '#dc2626' : '#16a34a') . '">' . intval($summary['incidencias']) . '</div></div>';
    echo '</div>';

    // --- TARJETAS: estado actual de las páginas fijas ---
    echo '<h2 class="sec">Estado actual</h2>';
    echo '<div class="tsm-grid">';
    $i = 0;
    foreach ($current as $c) {
        $down = !empty($c['alerting']) || $c['code'] === 0 || $c['code'] >= 500;
        $color = $down ? '#dc2626' : tsm_mon_speed_color(isset($c['total']) ? $c['total'] : null);
        echo '<div class="tsm-card anim" style="animation-delay:' . (60 + $i * 55) . 'ms"><div class="bar" style="background:' . $color . '"></div>';
        echo '<div class="name">' . esc_html($c['name']) . '</div>';
        echo '<a class="url" href="' . esc_url($c['url']) . '" target="_blank">' . esc_html(str_replace('https://', '', $c['url'])) . '</a>';
        if ($down) {
            echo '<div class="big" style="color:#dc2626;font-size:20px;">' . (!empty($c['alerting']) ? 'Con aviso' : 'Caída') . '</div>';
            echo '<div class="sub">HTTP ' . esc_html($c['code']) . '</div>';
        } else {
            echo '<div class="big" style="color:' . $color . '">' . esc_html(number_format((float)$c['total'], 2)) . 's</div>';
            echo '<div class="sub">servidor ' . esc_html($c['ttfb']) . 's · HTTP ' . esc_html($c['code']) . '</div>';
        }
        echo '</div>';
        $i++;
    }
    echo '</div>';

    // --- GRÁFICA DE TENDENCIA (últimos días) ---
    $daily = isset($d['daily']) ? $d['daily'] : array();
    if (count($daily) >= 2) {
        echo '<div class="tsm-panel anim">';
        echo '<div class="tsm-panelhead"><h2>Tendencia · últimos días</h2>';
        echo '<div class="tsm-seg" id="tsm-metric"><button type="button" data-v="uptime" class="on">Disponibilidad</button><button type="button" data-v="speed">Velocidad</button></div></div>';
        echo '<div style="position:relative;height:300px;margin-top:6px"><canvas id="tsm-chart"></canvas></div>';
        echo '<div class="tsm-note" id="tsm-note"></div>';
        echo '</div>';
    }

    // --- TABLA: velocidad por página (semana) — fijas + fichas rotadas ---
    echo '<h2 class="sec">Velocidad por página · esta semana</h2>';
    echo '<p class="note">Cada comprobación mide las páginas fijas y 1 ficha al azar de cada una de las 6 categorías. "Veces" = comprobaciones esta semana; "Velocidad media" = tiempo medio de carga.</p>';
    if (empty($week)) {
        echo '<p style="color:#6b7280;">Aún sin datos de esta semana.</p>';
    } else {
        usort($week, function ($a, $b) { return strcmp(($a['cat'] ?: '') . $a['name'], ($b['cat'] ?: '') . $b['name']); });
        echo '<table class="tsm-log"><thead><tr><th>Página</th><th>Veces</th><th>Velocidad media</th><th>Servidor (TTFB)</th><th>Incidencias</th></tr></thead><tbody>';
        foreach ($week as $w) {
            $u = str_replace('https://thaispamassage.es', '', $w['url']);
            $cat = $w['cat'] ? '<span class="cat"> · ' . esc_html($w['cat']) . '</span>' : '';
            echo '<tr><td><b>' . esc_html($w['name']) . '</b>' . $cat . '<br><a href="' . esc_url($w['url']) . '" target="_blank" style="color:#a3a3a3;font-size:11px;">' . esc_html($u) . '</a></td>'
                . '<td style="font-weight:800;">' . intval($w['count']) . '</td>'
                . '<td style="color:' . tsm_mon_speed_color($w['avg_total']) . ';font-weight:700;">' . esc_html($w['avg_total']) . 's</td>'
                . '<td style="color:#6b7280;">' . esc_html($w['avg_ttfb']) . 's</td>'
                . '<td style="color:' . ($w['down'] ? '#dc2626' : '#9ca3af') . ';font-weight:700;">' . intval($w['down']) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    // --- INCIDENCIAS ---
    echo '<h2 class="sec">Historial de incidencias</h2>';
    if (empty($alerts)) {
        echo '<p style="color:#16a34a;font-weight:600;">Sin incidencias registradas.</p>';
    } else {
        echo '<table class="tsm-log"><thead><tr><th>Cuándo</th><th>Tipo</th><th>Página</th><th>Detalle</th></tr></thead><tbody>';
        foreach (array_slice($alerts, 0, 30) as $a) {
            $isRec = (isset($a['type']) && $a['type'] === 'recovery');
            echo '<tr><td>' . esc_html(wp_date('j M, H:i', strtotime($a['ts']))) . '</td>'
                . '<td style="color:' . ($isRec ? '#16a34a' : '#dc2626') . ';font-weight:700;">&#9679; ' . ($isRec ? 'Recuperado' : 'Aviso') . '</td>'
                . '<td>' . esc_html(isset($a['name']) ? $a['name'] : '') . '</td>'
                . '<td>' . esc_html(isset($a['detail']) ? $a['detail'] : '') . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    echo '<p class="tsm-foot">Informe semanal automático cada viernes por la mañana · comprobación cada 5 min desde el servidor propio de <a href="https://dorica.agency/" target="_blank">dorica.agency</a> (sin servicios externos)</p>';
    echo '</div>';

    // --- JS: contadores animados + gráfica de tendencia ---
    $chartData = array();
    foreach ($daily as $x) $chartData[] = array('date' => $x['date'], 'uptime' => $x['uptime'], 'speed' => $x['avg_total']);
    ?>
    <script>
    (function () {
      // Contador animado en KPIs y tarjetas (respeta decimales y sufijo % o s)
      function countUp(el) {
        var m = el.textContent.trim().match(/^(\d+(?:\.\d+)?)(.*)$/); if (!m) return;
        var target = parseFloat(m[1]), suffix = m[2], dec = (m[1].split('.')[1] || '').length, t0 = null;
        function step(ts) { if (!t0) t0 = ts; var p = Math.min((ts - t0) / 900, 1); var v = target * (1 - Math.pow(1 - p, 3));
          el.textContent = (dec ? v.toFixed(dec) : Math.round(v)) + suffix; if (p < 1) requestAnimationFrame(step); }
        requestAnimationFrame(step);
      }
      document.querySelectorAll('.tsm-mon .tsm-kpi .v, .tsm-card .big').forEach(countUp);

      // Gráfica de tendencia
      var DATA = <?php echo wp_json_encode($chartData); ?>;
      var canvas = document.getElementById('tsm-chart');
      if (!canvas || !DATA.length) return;
      var metric = 'uptime', chart = null;
      function build() {
        if (typeof Chart === 'undefined') return;
        var labels = DATA.map(function (d) { return d.date; });
        var vals = DATA.map(function (d) { return metric === 'uptime' ? d.uptime : d.speed; });
        var col = metric === 'uptime' ? '#16a34a' : '#a99367';
        var ctx = canvas.getContext('2d');
        var g = ctx.createLinearGradient(0, 0, 0, 280); g.addColorStop(0, col + '33'); g.addColorStop(1, col + '00');
        if (chart) chart.destroy();
        chart = new Chart(ctx, {
          type: 'line',
          data: { labels: labels, datasets: [{ data: vals, borderColor: col, backgroundColor: g, fill: true, borderWidth: 2.5, pointRadius: 0, pointHoverRadius: 5, pointBackgroundColor: col, tension: .35 }] },
          options: {
            responsive: true, maintainAspectRatio: false, animation: { duration: 900, easing: 'easeOutQuart' },
            interaction: { mode: 'index', intersect: false },
            scales: {
              x: { ticks: { color: '#9ca3af', maxTicksLimit: 8, callback: function (v) { return new Date(labels[v]).toLocaleDateString('es-ES', { day: 'numeric', month: 'short' }); } }, grid: { display: false } },
              y: metric === 'uptime'
                ? { min: Math.max(0, Math.min(95, Math.floor(Math.min.apply(null, vals)))), max: 100, grid: { color: 'rgba(0,0,0,.05)' }, ticks: { color: '#9ca3af', callback: function (v) { return v + '%'; } } }
                : { min: 0, grid: { color: 'rgba(0,0,0,.05)' }, ticks: { color: '#9ca3af', callback: function (v) { return v + 's'; } } }
            },
            plugins: {
              legend: { display: false },
              tooltip: { backgroundColor: '#1e1e1e', padding: 12, cornerRadius: 10, titleColor: '#e7c98a',
                callbacks: {
                  title: function (it) { return new Date(it[0].label).toLocaleDateString('es-ES', { weekday: 'long', day: 'numeric', month: 'long' }); },
                  label: function (it) { return metric === 'uptime' ? ('Disponibilidad: ' + it.parsed.y + '%') : ('Velocidad media: ' + it.parsed.y + 's'); }
                } }
            }
          }
        });
        var note = document.getElementById('tsm-note');
        if (note) note.textContent = metric === 'uptime' ? 'Porcentaje de comprobaciones sin incidencia por día (más alto = mejor).' : 'Tiempo medio de carga por día (más bajo = mejor).';
      }
      document.querySelectorAll('#tsm-metric button').forEach(function (b) {
        b.addEventListener('click', function () {
          b.parentNode.querySelectorAll('button').forEach(function (x) { x.classList.remove('on'); });
          b.classList.add('on'); metric = b.dataset.v; build();
        });
      });
      if (document.readyState !== 'loading') build(); else document.addEventListener('DOMContentLoaded', build);
    })();
    </script>
    <?php
}
