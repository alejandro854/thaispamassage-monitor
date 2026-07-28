<?php
/**
 * Plugin Name: Monitor de velocidad
 * Description: Panel de monitorización de velocidad de thaispamassage.es. Los datos los genera un bot en GitHub Actions (mide cada hora móvil/escritorio con Google Lighthouse).
 * Version:     1.0.0
 * Author:      dorica.agency
 * Author URI:  https://dorica.agency/
 */
if (!defined('ABSPATH')) exit;

define('TSM_MON_SUMMARY_URL', 'https://raw.githubusercontent.com/alejandro854/thaispamassage-monitor/main/data/summary.json');
define('TSM_MON_REPO_URL',    'https://github.com/alejandro854/thaispamassage-monitor');
define('TSM_MON_VER', '1.0.0');

/* ---- Menú en el admin --------------------------------------------------- */
add_action('admin_menu', function () {
    add_menu_page('Monitorización web', 'Monitorización web', 'manage_options',
        'tsm-monitor', 'tsm_mon_render_page', 'dashicons-chart-area', 58);
});

/* ---- Datos (summary.json de GitHub, cacheado 20 min) -------------------- */
function tsm_mon_get_summary($force = false) {
    if (!$force) { $c = get_transient('tsm_mon_summary'); if ($c) return $c; }
    $res = wp_remote_get(TSM_MON_SUMMARY_URL, array('timeout' => 15));
    if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) {
        return get_transient('tsm_mon_summary') ?: null;
    }
    $data = json_decode(wp_remote_retrieve_body($res), true);
    if (!is_array($data)) return get_transient('tsm_mon_summary') ?: null;
    set_transient('tsm_mon_summary', $data, 20 * MINUTE_IN_SECONDS);
    return $data;
}

/* ---- Cargar Chart.js solo en nuestra página ----------------------------- */
add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'toplevel_page_tsm-monitor') return;
    wp_enqueue_script('tsm-chartjs', plugins_url('chart.umd.min.js', __FILE__), array(), '4.4.4', true);
});

/* ---- Helpers de color --------------------------------------------------- */
function tsm_mon_score_color($s) { return $s >= 90 ? '#16a34a' : ($s >= 50 ? '#d97706' : '#dc2626'); }

/* ---- Página del panel --------------------------------------------------- */
function tsm_mon_render_page() {
    if (!current_user_can('manage_options')) return;
    if (isset($_GET['refresh'])) { tsm_mon_get_summary(true); }
    $d = tsm_mon_get_summary();

    echo '<div class="wrap tsm-mon">';
    echo '<h1 style="display:flex;align-items:center;gap:12px;">Monitorización web '
        . '<a href="' . esc_url(add_query_arg('refresh', 1)) . '" class="button">↻ Actualizar</a></h1>';

    if (!$d) {
        echo '<div class="notice notice-warning"><p>Aún no hay datos. El bot genera el primer resumen en su próxima ejecución (cada hora). '
            . 'Puedes ver el estado en <a href="' . esc_url(TSM_MON_REPO_URL . '/actions') . '" target="_blank">GitHub Actions</a>.</p></div></div>';
        return;
    }

    $pages    = isset($d['pages']) ? $d['pages'] : array();
    $current  = isset($d['current']) ? $d['current'] : array();
    $profiles = isset($d['profiles']) ? $d['profiles'] : array('mobile' => 'Móvil', 'desktop' => 'Escritorio');
    $alerting = isset($d['alerting']) ? $d['alerting'] : array();
    $updated  = isset($d['updated']) ? $d['updated'] : null;

    // --- estado general ---
    $global = empty($alerting) ? array('ok', '#16a34a', '#dcfce7', 'Todo funcionando correctamente')
                               : array('bad', '#dc2626', '#fee2e2', count($alerting) . ' aviso(s) activo(s)');
    echo '<div style="display:inline-block;margin:6px 0 14px;padding:8px 16px;border-radius:999px;background:' . $global[2] . ';color:' . $global[1] . ';font-weight:700;">● ' . esc_html($global[3]) . '</div>';
    if ($updated) {
        echo '<span style="color:#6b7280;margin-left:12px;">Última medición: ' . esc_html(wp_date('j M Y, H:i', strtotime($updated))) . ' · comprueba cada hora</span>';
    }

    // --- tarjetas de estado por página ---
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px;margin:8px 0 26px;">';
    foreach ($pages as $pg) {
        echo '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:14px 16px;box-shadow:0 1px 2px rgba(0,0,0,.04);">';
        echo '<div style="font-weight:700;color:#111;margin-bottom:2px;">' . esc_html($pg['name']) . '</div>';
        echo '<a href="' . esc_url($pg['url']) . '" target="_blank" style="font-size:11px;color:#9ca3af;text-decoration:none;">' . esc_html(str_replace('https://', '', $pg['url'])) . '</a>';
        echo '<div style="display:flex;gap:18px;margin-top:12px;">';
        foreach (array('mobile', 'desktop') as $ff) {
            $v = isset($current[$pg['key'] . ':' . $ff]) ? $current[$pg['key'] . ':' . $ff] : null;
            echo '<div style="flex:1;"><div style="font-size:10px;color:#9ca3af;text-transform:uppercase;letter-spacing:.04em;margin-bottom:3px;">' . esc_html($profiles[$ff]) . '</div>';
            if (!$v || !empty($v['down'])) {
                echo '<div style="font-size:15px;font-weight:800;color:#dc2626;">CAÍDA</div>';
            } else {
                $c = tsm_mon_score_color($v['score']);
                $sec = ($pg['key'] === 'checkout' && isset($v['ttfb']) && $v['ttfb'] !== null)
                    ? ('servidor ' . $v['ttfb'] . 's') : ('LCP ' . (isset($v['lcp']) ? $v['lcp'] . 's' : '—'));
                echo '<div style="font-size:22px;font-weight:800;color:' . $c . ';line-height:1;">' . intval($v['score']) . '</div>';
                echo '<div style="font-size:11px;color:#6b7280;margin-top:2px;">' . esc_html($sec) . '</div>';
            }
            echo '</div>';
        }
        echo '</div></div>';
    }
    echo '</div>';

    // --- controles + gráfico ---
    echo '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px;margin-bottom:24px;">';
    echo '<div id="tsm-controls" style="display:flex;flex-wrap:wrap;gap:18px;align-items:center;margin-bottom:14px;font-size:13px;">'
        . '<span><b>Métrica:</b> <select id="tsm-metric"><option value="score">Puntuación (0-100)</option><option value="lcp">LCP (segundos)</option></select></span>'
        . '<span><b>Dispositivo:</b> <select id="tsm-device"><option value="both">Ambos</option><option value="mobile">Móvil</option><option value="desktop">Escritorio</option></select></span>'
        . '<span><b>Rango:</b> <select id="tsm-range"><option value="7">7 días</option><option value="30">30 días</option></select></span>'
        . '</div>';
    echo '<div style="position:relative;height:340px;"><canvas id="tsm-chart"></canvas></div>';
    echo '</div>';

    // --- historial de incidencias ---
    $log = isset($d['alertsLog']) ? array_reverse($d['alertsLog']) : array();
    echo '<h2>Historial de incidencias</h2>';
    if (empty($log)) {
        echo '<p style="color:#16a34a;font-weight:600;">Sin incidencias registradas. 🎉</p>';
    } else {
        echo '<table class="widefat striped" style="max-width:820px;"><thead><tr><th>Cuándo</th><th>Tipo</th><th>Página</th><th>Dispositivo</th><th>Detalle</th></tr></thead><tbody>';
        foreach (array_slice($log, 0, 30) as $a) {
            $isRec = (isset($a['type']) && $a['type'] === 'recovery');
            echo '<tr><td>' . esc_html(wp_date('j M, H:i', strtotime($a['ts']))) . '</td>'
                . '<td style="color:' . ($isRec ? '#16a34a' : '#dc2626') . ';font-weight:700;">' . ($isRec ? '✅ Recuperado' : '⚠️ Aviso') . '</td>'
                . '<td>' . esc_html(isset($a['page']) ? $a['page'] : '') . '</td>'
                . '<td>' . esc_html(isset($a['profile']) ? $a['profile'] : '') . '</td>'
                . '<td>' . esc_html(isset($a['detail']) ? $a['detail'] : '') . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    echo '<p style="margin-top:18px;color:#9ca3af;font-size:12px;">Datos generados automáticamente por el bot · <a href="' . esc_url(TSM_MON_REPO_URL) . '" target="_blank">repositorio</a>. Medido con Google Lighthouse en móvil (5G) y escritorio. · Desarrollado por <a href="https://dorica.agency/" target="_blank">dorica.agency</a></p>';
    echo '</div>';

    // --- datos + JS del gráfico ---
    $series = isset($d['series']) ? $d['series'] : array();
    ?>
    <script>
    (function(){
      var SERIES = <?php echo wp_json_encode($series); ?>;
      var PAGES  = <?php echo wp_json_encode($pages); ?>;
      var PROFS  = <?php echo wp_json_encode($profiles); ?>;
      var COLORS = ['#a0926a','#2563eb','#16a34a','#9d432c','#7c3aed','#0891b2'];
      var chart = null;

      function build(){
        if (typeof Chart === 'undefined') return;
        var metric = document.getElementById('tsm-metric').value;
        var device = document.getElementById('tsm-device').value;
        var days   = parseInt(document.getElementById('tsm-range').value, 10);
        var cutoff = Date.now() - days*864e5;
        var pts = SERIES.filter(function(p){ return Date.parse(p.ts) >= cutoff; });
        var ffs = device === 'both' ? ['mobile','desktop'] : [device];
        var datasets = [];
        PAGES.forEach(function(pg, i){
          ffs.forEach(function(ff){
            var id = pg.key + ':' + ff;
            var data = pts.map(function(p){
              var v = p[id];
              var y = (!v || v.down) ? null : (metric === 'score' ? v.score : v.lcp);
              return { x: p.ts, y: y };
            });
            datasets.push({
              label: pg.name + ' · ' + (PROFS[ff]||ff),
              data: data,
              borderColor: COLORS[i % COLORS.length],
              backgroundColor: COLORS[i % COLORS.length],
              borderDash: ff === 'desktop' ? [5,4] : [],
              borderWidth: 2, pointRadius: 0, pointHoverRadius: 4, tension: .3, spanGaps: true
            });
          });
        });
        var labels = pts.map(function(p){ return p.ts; });
        if (chart) chart.destroy();
        chart = new Chart(document.getElementById('tsm-chart'), {
          type: 'line',
          data: { labels: labels, datasets: datasets },
          options: {
            responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
            scales: {
              x: { ticks: { maxTicksLimit: 8, callback: function(v){ var d=new Date(labels[v]); return d.toLocaleDateString('es-ES',{day:'numeric',month:'short'}); } }, grid: { display:false } },
              y: metric === 'score'
                 ? { min:0, max:100, title:{display:true,text:'Puntuación'} }
                 : { min:0, title:{display:true,text:'LCP (s)'} }
            },
            plugins: { legend: { position:'bottom', labels:{ boxWidth:14, font:{size:11} } },
              tooltip: { callbacks: { title: function(it){ return new Date(it[0].label).toLocaleString('es-ES'); } } } }
          }
        });
      }
      function ready(){ ['tsm-metric','tsm-device','tsm-range'].forEach(function(id){ document.getElementById(id).addEventListener('change', build); }); build(); }
      if (document.readyState !== 'loading') ready(); else document.addEventListener('DOMContentLoaded', ready);
    })();
    </script>
    <?php
}
