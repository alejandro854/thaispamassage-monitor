# Monitorización thaispamassage.es

Monitorización **auto-alojada en el servidor de dorica.agency (cdmon)**, sin depender de servicios externos.

## Qué hay aquí (lo único vivo)
- **`tsm-uptime-cdmon.php`** — el monitor. Se despliega en cdmon como `web/tsm-uptime.php` y lo dispara un cron por URL **cada 5 min**. Comprueba las páginas clave (caídas/lentitud), avisa por email y genera el informe semanal (viernes AM). Endpoint `?panel=1` (JSON) para el panel de WordPress.
- **`wp-plugin/tsm-monitor/`** — plugin del panel "Monitorización" en wp-admin de Thai. Lee el JSON de dorica (solo al abrir la página de admin; cero impacto en la web pública).

## Ligero a propósito
Cada 5 min solo mira 4 páginas **cacheadas** (baratísimo). El checkout y las fichas (que generan PHP) se comprueban solo **cada 30 min**, para no cargar el servidor de Thai.

## Retirado (2026-08-05)
El antiguo **bot de velocidad con Lighthouse en GitHub Actions** queda **eliminado**. Auditaba a lo bestia cada hora (user-agent `ThaiSpaSpeedBot`, IPs de Azure/GitHub) y cargaba el servidor. Sustituido por este monitor ligero en dorica.
