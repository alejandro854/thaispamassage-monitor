<?php
/**
 * Plugin Name: Monitor de velocidad
 * Description: Panel de monitorización de velocidad de thaispamassage.es. Los datos los genera un bot en GitHub Actions (mide cada hora móvil/escritorio con Google Lighthouse).
 * Version:     1.1.0
 * Author:      dorica.agency
 * Author URI:  https://dorica.agency/
 */
if (!defined('ABSPATH')) exit;

define('TSM_MON_SUMMARY_URL', 'https://raw.githubusercontent.com/alejandro854/thaispamassage-monitor/main/data/summary.json');
define('TSM_MON_CSV_URL',     'https://raw.githubusercontent.com/alejandro854/thaispamassage-monitor/main/data/history.csv');
define('TSM_MON_REPO_URL',    'https://github.com/alejandro854/thaispamassage-monitor');
define('TSM_MON_LOGO',        'https://thaispamassage.es/wp-content/uploads/2022/06/logo-thaispamassage.png');

add_action('admin_menu', function () {
    add_menu_page('Monitorización', 'Monitorización', 'manage_options',
        'tsm-monitor', 'tsm_mon_render_page', 'dashicons-chart-area', 58);
});

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

add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'toplevel_page_tsm-monitor') return;
    wp_enqueue_script('tsm-chartjs', plugins_url('chart.umd.min.js', __FILE__), array(), '4.4.4', true);
});

function tsm_mon_score_color($s) { return $s >= 90 ? '#16a34a' : ($s >= 50 ? '#d97706' : '#dc2626'); }

function tsm_mon_render_page() {
    if (!current_user_can('manage_options')) return;
    if (isset($_GET['refresh'])) { tsm_mon_get_summary(true); }
    $d = tsm_mon_get_summary();

    // Colores por página (para chips y líneas del gráfico)
    $PALETTE = array('#a99367', '#2563eb', '#16a34a', '#9d432c', '#7c3aed', '#0891b2');

    ?>
    <style>
    .tsm-mon{--ink:#1e1e1e;--gold:#a99367;--paper:#faf8f2;--line:#ece7db;--green:#16a34a;--amber:#d97706;--red:#dc2626;--gray:#6b7280;
      margin:16px 20px 40px 0;color:#1f2430;font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif}
    .tsm-mon *{box-sizing:border-box}
    @keyframes tsmUp{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:none}}
    .tsm-mon .anim{animation:tsmUp .5s cubic-bezier(.22,1,.36,1) both}
    /* Hero */
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
    /* Tarjetas */
    .tsm-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:16px;margin:20px 0 26px}
    .tsm-card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:16px 18px;position:relative;overflow:hidden;
      transition:transform .28s cubic-bezier(.22,1,.36,1),box-shadow .28s}
    .tsm-card:hover{transform:translateY(-4px);box-shadow:0 16px 32px rgba(30,25,15,.1)}
    .tsm-card .bar{position:absolute;left:0;top:0;bottom:0;width:4px}
    .tsm-card .name{font-weight:700;color:#1f2430;font-size:14.5px}
    .tsm-card .url{font-size:11px;color:#a3a3a3;text-decoration:none}
    .tsm-card .row{display:flex;gap:20px;margin-top:14px}
    .tsm-card .dev{flex:1}
    .tsm-card .devlbl{font-size:10px;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px}
    .tsm-card .score{font-size:30px;font-weight:800;line-height:1}
    .tsm-card .sub{font-size:11.5px;color:#6b7280;margin-top:3px}
    /* Panel gráfico */
    .tsm-panel{background:#fff;border:1px solid var(--line);border-radius:18px;padding:22px 22px 18px;margin-bottom:26px}
    .tsm-panel h2{margin:0 0 4px;font-size:16px}
    .tsm-controls{display:flex;flex-wrap:wrap;gap:22px;align-items:center;margin:14px 0}
    .tsm-seg{display:inline-flex;background:#f3f1ea;border-radius:10px;padding:3px;gap:2px}
    .tsm-seg button{border:0;background:transparent;padding:6px 14px;border-radius:8px;font-size:12.5px;font-weight:600;color:#6b7280;cursor:pointer;transition:.2s}
    .tsm-seg button.on{background:#fff;color:#1f2430;box-shadow:0 1px 3px rgba(0,0,0,.1)}
    .tsm-cseglbl{font-size:11px;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-right:8px}
    .tsm-chips{display:flex;flex-wrap:wrap;gap:8px;margin:6px 0 12px}
    .tsm-chip{display:inline-flex;align-items:center;gap:7px;border:1px solid var(--line);background:#fff;border-radius:999px;
      padding:5px 12px;font-size:12.5px;font-weight:600;color:#374151;cursor:pointer;transition:.2s;user-select:none}
    .tsm-chip .cdot{width:10px;height:10px;border-radius:50%}
    .tsm-chip.off{opacity:.4;background:#f6f6f6}
    .tsm-chip:hover{border-color:#c9c2b2}
    .tsm-note{font-size:11.5px;color:#9ca3af;margin:8px 2px 0}
    /* Tabla */
    .tsm-mon h2.sec{font-size:16px;margin:26px 0 10px}
    .tsm-log{width:100%;max-width:860px;border-collapse:separate;border-spacing:0;background:#fff;border:1px solid var(--line);border-radius:14px;overflow:hidden}
    .tsm-log th{background:#f7f6f1;text-align:left;padding:11px 14px;font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.04em}
    .tsm-log td{padding:11px 14px;border-top:1px solid #f0eee7;font-size:13px}
    .tsm-foot{margin-top:20px;color:#a3a3a3;font-size:12px}
    .tsm-foot a{color:var(--gold)}
    </style>

    <div class="wrap tsm-mon">
    <?php
    if (!$d) {
        echo '<div class="tsm-hero anim"><div class="t"><div class="lbl">Monitorización web</div><h1>Sin datos todavía</h1>'
            . '<div class="meta">El bot generará el primer resumen en su próxima ejecución (cada hora).</div></div>'
            . '<a class="tsm-refresh" href="' . esc_url(TSM_MON_REPO_URL . '/actions') . '" target="_blank">Ver estado del bot ↗</a></div></div>';
        return;
    }

    $pages    = isset($d['pages']) ? $d['pages'] : array();
    $current  = isset($d['current']) ? $d['current'] : array();
    $profiles = isset($d['profiles']) ? $d['profiles'] : array('mobile' => 'Móvil', 'desktop' => 'Escritorio');
    $alerting = isset($d['alerting']) ? $d['alerting'] : array();
    $updated  = isset($d['updated']) ? $d['updated'] : null;

    // Frescura: si hace mucho de la última medición, algo va mal con el bot
    $stale = false; $ago = '';
    if ($updated) {
        $mins = (time() - strtotime($updated)) / 60;
        $stale = $mins > 150; // >2,5h sin medir = sospechoso
        $ago = $mins < 90 ? 'hace ' . max(1, round($mins)) . ' min' : 'hace ' . round($mins / 60, 1) . ' h';
    }
    if ($stale) { $st = array('#f59e0b', '#fffbeb', 'El bot lleva ' . $ago . ' sin medir — revísalo'); }
    elseif (empty($alerting)) { $st = array('#16a34a', 'rgba(22,163,74,.16)', 'Todo funcionando correctamente'); }
    else { $st = array('#f87171', 'rgba(220,38,38,.18)', count($alerting) . ' aviso(s) activo(s)'); }

    // --- HERO ---
    echo '<div class="tsm-hero anim"><img src="' . esc_url(TSM_MON_LOGO) . '" alt="Thai Spa Massage">';
    echo '<div class="t"><div class="lbl">Monitorización web</div><h1>Velocidad de la web</h1>';
    echo '<div class="meta">' . ($updated ? 'Última medición: ' . esc_html(wp_date('j M Y, H:i', strtotime($updated))) . ' · ' . esc_html($ago) : '') . ' · comprueba cada hora</div></div>';
    echo '<div class="tsm-status" style="background:' . $st[1] . ';color:' . $st[0] . ';"><span class="dot" style="background:' . $st[0] . '"></span>' . esc_html($st[2]) . '</div>';
    echo '<a class="tsm-refresh" href="' . esc_url(add_query_arg('refresh', 1)) . '">↻ Actualizar</a></div>';

    // --- TARJETAS ---
    echo '<div class="tsm-grid">';
    $i = 0;
    foreach ($pages as $pg) {
        $color = $PALETTE[$i % count($PALETTE)];
        echo '<div class="tsm-card anim" style="animation-delay:' . (60 + $i * 55) . 'ms"><div class="bar" style="background:' . $color . '"></div>';
        echo '<div class="name">' . esc_html($pg['name']) . '</div>';
        echo '<a class="url" href="' . esc_url($pg['url']) . '" target="_blank">' . esc_html(str_replace('https://', '', $pg['url'])) . '</a>';
        echo '<div class="row">';
        foreach (array('mobile', 'desktop') as $ff) {
            $v = isset($current[$pg['key'] . ':' . $ff]) ? $current[$pg['key'] . ':' . $ff] : null;
            echo '<div class="dev"><div class="devlbl">' . esc_html($profiles[$ff]) . '</div>';
            if (!$v || !empty($v['down'])) {
                echo '<div class="score" style="color:#dc2626;font-size:18px;">Caída</div>';
            } else {
                $c = tsm_mon_score_color($v['score']);
                $sec = ($pg['key'] === 'checkout' && isset($v['ttfb']) && $v['ttfb'] !== null)
                    ? ('servidor ' . $v['ttfb'] . 's') : ('LCP ' . (isset($v['lcp']) ? $v['lcp'] . 's' : '—'));
                echo '<div class="score" style="color:' . $c . '" data-count="' . intval($v['score']) . '">0</div>';
                echo '<div class="sub">' . esc_html($sec) . '</div>';
            }
            echo '</div>';
        }
        echo '</div></div>';
        $i++;
    }
    echo '</div>';

    // --- GRÁFICO ---
    echo '<div class="tsm-panel anim" style="animation-delay:120ms"><h2>Evolución</h2>';
    echo '<div class="tsm-controls">'
        . '<div><span class="tsm-cseglbl">Métrica</span><span class="tsm-seg" id="tsm-metric"><button data-v="score" class="on">Puntuación</button><button data-v="lcp">LCP</button></span></div>'
        . '<div><span class="tsm-cseglbl">Dispositivo</span><span class="tsm-seg" id="tsm-device"><button data-v="mobile" class="on">Móvil</button><button data-v="desktop">Escritorio</button><button data-v="both">Ambos</button></span></div>'
        . '<div><span class="tsm-cseglbl">Rango</span><span class="tsm-seg" id="tsm-range"><button data-v="7" class="on">7 días</button><button data-v="30">30 días</button></span></div>'
        . '</div>';
    echo '<div class="tsm-chips" id="tsm-chips"></div>';
    echo '<div style="position:relative;height:360px"><canvas id="tsm-chart"></canvas></div>';
    echo '<div class="tsm-note" id="tsm-note"></div>';
    echo '</div>';

    // --- INCIDENCIAS ---
    $log = isset($d['alertsLog']) ? array_reverse($d['alertsLog']) : array();
    echo '<h2 class="sec">Historial de incidencias</h2>';
    if (empty($log)) {
        echo '<p style="color:#16a34a;font-weight:600;">Sin incidencias registradas.</p>';
    } else {
        echo '<table class="tsm-log"><thead><tr><th>Cuándo</th><th>Tipo</th><th>Página</th><th>Dispositivo</th><th>Detalle</th></tr></thead><tbody>';
        foreach (array_slice($log, 0, 30) as $a) {
            $isRec = (isset($a['type']) && $a['type'] === 'recovery');
            echo '<tr><td>' . esc_html(wp_date('j M, H:i', strtotime($a['ts']))) . '</td>'
                . '<td style="color:' . ($isRec ? '#16a34a' : '#dc2626') . ';font-weight:700;">&#9679; ' . ($isRec ? 'Recuperado' : 'Aviso') . '</td>'
                . '<td>' . esc_html(isset($a['page']) ? $a['page'] : '') . '</td>'
                . '<td>' . esc_html(isset($a['profile']) ? $a['profile'] : '') . '</td>'
                . '<td>' . esc_html(isset($a['detail']) ? $a['detail'] : '') . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    echo '<p class="tsm-foot">Medido cada hora con Google Lighthouse (móvil 5G y escritorio) · <a href="' . esc_url(TSM_MON_CSV_URL) . '" target="_blank">descargar datos (CSV)</a> · desarrollado por <a href="https://dorica.agency/" target="_blank">dorica.agency</a></p>';
    echo '</div>';

    $series = isset($d['series']) ? $d['series'] : array();
    ?>
    <script>
    (function(){
      var SERIES=<?php echo wp_json_encode($series); ?>, PAGES=<?php echo wp_json_encode($pages); ?>, PROFS=<?php echo wp_json_encode($profiles); ?>;
      var COLORS=<?php echo wp_json_encode($PALETTE); ?>;
      var state={metric:'score',device:'mobile',range:'7'}, hidden={}, chart=null;

      // contador animado en las tarjetas
      document.querySelectorAll('.tsm-card .score[data-count]').forEach(function(el){
        var target=+el.getAttribute('data-count'), t0=null;
        function step(ts){ if(!t0)t0=ts; var p=Math.min((ts-t0)/900,1); el.textContent=Math.round(target*(1-Math.pow(1-p,3))); if(p<1)requestAnimationFrame(step); }
        requestAnimationFrame(step);
      });

      // resaltar la línea de una página (atenuar las demás) al pasar el ratón por su chip
      function highlight(key){
        if(!chart)return;
        chart.data.datasets.forEach(function(ds){
          var on = key===null || ds.pgkey===key;
          ds.borderColor = on ? ds._col : ds._col+'22';
          ds.borderWidth = (key!==null && ds.pgkey===key) ? 3.5 : 2.5;
        });
        chart.update('none');
      }
      // chips de leyenda (una por página, nombre corto + color)
      var chipsEl=document.getElementById('tsm-chips');
      PAGES.forEach(function(pg,i){
        var c=document.createElement('span'); c.className='tsm-chip'; c.dataset.key=pg.key;
        c.innerHTML='<span class="cdot" style="background:'+COLORS[i%COLORS.length]+'"></span>'+pg.name;
        c.addEventListener('click',function(){ hidden[pg.key]=!hidden[pg.key]; c.classList.toggle('off',hidden[pg.key]); build(); });
        c.addEventListener('mouseenter',function(){ if(!hidden[pg.key]) highlight(pg.key); });
        c.addEventListener('mouseleave',function(){ highlight(null); });
        chipsEl.appendChild(c);
      });

      // tabs segmentados
      ['metric','device','range'].forEach(function(k){
        document.querySelectorAll('#tsm-'+k+' button').forEach(function(b){
          b.addEventListener('click',function(){
            b.parentNode.querySelectorAll('button').forEach(function(x){x.classList.remove('on');});
            b.classList.add('on'); state[k]=b.dataset.v; build();
          });
        });
      });

      function fill(ctx,color){ var g=ctx.createLinearGradient(0,0,0,320); g.addColorStop(0,color+'26'); g.addColorStop(1,color+'00'); return g; }

      function build(){
        if(typeof Chart==='undefined') return;
        var days=+state.range, cutoff=Date.now()-days*864e5;
        var pts=SERIES.filter(function(p){return Date.parse(p.ts)>=cutoff;});
        // 30 días: agregar por día para que sea ligero y legible
        if(days===30){ var by={}; pts.forEach(function(p){var d=p.ts.slice(0,10);(by[d]=by[d]||[]).push(p);});
          pts=Object.keys(by).sort().map(function(d){var arr=by[d],o={ts:d+'T12:00:00Z'};
            PAGES.forEach(function(pg){['mobile','desktop'].forEach(function(ff){var id=pg.key+':'+ff,s=0,l=0,n=0;
              arr.forEach(function(p){var v=p[id];if(v&&!v.down){s+=v.score;l+=(v.lcp||0);n++;}}); if(n)o[id]={score:Math.round(s/n),lcp:+(l/n).toFixed(2)};});}); return o;}); }
        var ffs=state.device==='both'?['mobile','desktop']:[state.device];
        var ctx=document.getElementById('tsm-chart').getContext('2d');
        var ds=[];
        PAGES.forEach(function(pg,i){
          if(hidden[pg.key])return;
          ffs.forEach(function(ff){
            var id=pg.key+':'+ff, col=COLORS[i%COLORS.length];
            ds.push({pgkey:pg.key,_col:col,label:pg.name+(ffs.length>1?' · '+(PROFS[ff]||ff):''),
              data:pts.map(function(p){var v=p[id];return{x:p.ts,y:(!v||v.down)?null:(state.metric==='score'?v.score:v.lcp)};}),
              borderColor:col, backgroundColor:(ffs.length===1?fill(ctx,col):'transparent'), fill:ffs.length===1,
              borderDash:ff==='desktop'&&ffs.length>1?[5,4]:[], borderWidth:2.5, pointRadius:0, pointHoverRadius:5,
              pointBackgroundColor:col, tension:.35, spanGaps:true});
          });
        });
        var labels=pts.map(function(p){return p.ts;});
        // Zonas de fondo (verde=bien, ámbar=mejorable, rojo=malo) para leer el gráfico de un vistazo
        var zones={id:'tsmzones',beforeDatasetsDraw:function(ch){var a=ch.chartArea,y=ch.scales.y,cx=ch.ctx;if(!a)return;
          var bands=state.metric==='score'?[[90,100,'#16a34a'],[50,90,'#d97706'],[0,50,'#dc2626']]
                                           :[[0,2.5,'#16a34a'],[2.5,4,'#d97706'],[4,(y.max||10),'#dc2626']];
          cx.save();bands.forEach(function(b){var y1=y.getPixelForValue(b[1]),y2=y.getPixelForValue(b[0]);
            cx.fillStyle=b[2]+'14';cx.fillRect(a.left,Math.min(y1,y2),a.right-a.left,Math.abs(y2-y1));});cx.restore();}};
        function qual(v){ return state.metric==='score' ? (v>=90?'bien':v>=50?'mejorable':'malo') : (v<=2.5?'bien':v<4?'mejorable':'malo'); }
        if(chart)chart.destroy();
        chart=new Chart(ctx,{type:'line',data:{labels:labels,datasets:ds},plugins:[zones],options:{
          responsive:true,maintainAspectRatio:false,animation:{duration:900,easing:'easeOutQuart'},
          interaction:{mode:'index',intersect:false},
          scales:{x:{ticks:{maxTicksLimit:7,color:'#9ca3af',callback:function(v){return new Date(labels[v]).toLocaleDateString('es-ES',{day:'numeric',month:'short'});}},grid:{display:false}},
            y:state.metric==='score'?{min:0,max:100,grid:{color:'rgba(0,0,0,.04)'},ticks:{color:'#9ca3af'},title:{display:true,text:'Puntuación (más alto = mejor)',color:'#9ca3af'}}
                                     :{min:0,grid:{color:'rgba(0,0,0,.04)'},ticks:{color:'#9ca3af'},title:{display:true,text:'LCP en segundos (más bajo = mejor)',color:'#9ca3af'}}},
          plugins:{legend:{display:false},
            tooltip:{backgroundColor:'#1e1e1e',padding:12,cornerRadius:10,titleColor:'#e7c98a',
              callbacks:{title:function(it){return new Date(it[0].label).toLocaleString('es-ES');},
                label:function(it){return it.dataset.label+': '+it.parsed.y+(state.metric==='lcp'?'s':'')+' · '+qual(it.parsed.y);}}}}
        }});
        document.getElementById('tsm-note').innerHTML =
          '<span style="display:inline-block;width:10px;height:10px;border-radius:3px;background:#16a34a;vertical-align:middle"></span> bien &nbsp; '
          + '<span style="display:inline-block;width:10px;height:10px;border-radius:3px;background:#d97706;vertical-align:middle"></span> mejorable &nbsp; '
          + '<span style="display:inline-block;width:10px;height:10px;border-radius:3px;background:#dc2626;vertical-align:middle"></span> malo &nbsp; · &nbsp; '
          + (state.device==='both' ? 'línea sólida = móvil, discontinua = escritorio · ' : '')
          + 'toca una página en la leyenda para mostrarla u ocultarla.';
      }
      if(document.readyState!=='loading')build(); else document.addEventListener('DOMContentLoaded',build);
    })();
    </script>
    <?php
}
