# Monitor de velocidad — Thai Spa Massage

Bot que **cada hora** comprueba la velocidad de la web y **avisa por email** solo cuando
se ralentiza de verdad. Corre en la nube (GitHub Actions), no depende de ningún ordenador
encendido y no tiene coste de servidor.

## Qué mide

Tres páginas (por orden de importancia), en **móvil (5G)** y **escritorio**:

1. **Inicio** — `https://thaispamassage.es/`
2. **Ficha de masaje** — `https://thaispamassage.es/masaje-tailandes/`
3. **Elegir masaje (regalo)** — `https://thaispamassage.es/regala-thai-spa-massage/`

Usa **Google Lighthouse** (lo mismo que PageSpeed) y guarda un **historial completo**
en `data/history.csv` — datos, no opiniones.

## Cuándo avisa

Umbrales basados en **Core Web Vitals** de Google (el estándar). Avisa cuando el
rendimiento es **realmente malo**, no un simple "mejorable":

| Métrica | Móvil (5G) | Escritorio |
|---|---|---|
| LCP (cuándo se ve el contenido) | > 4,0 s | > 2,5 s |
| Puntuación de rendimiento | < 50 | < 50 |
| Página caída / error | siempre | siempre |

Solo alerta tras **2 comprobaciones seguidas en rojo** (evita falsas alarmas por el ruido
normal de la medición) y envía un email de **"recuperado"** cuando vuelve a la normalidad.
Se puede afinar todo en `config.js`.

> Medido en 5G la web va a ~99/100 en móvil, así que en la práctica solo saltará si hay
> una degradación real o una caída.

## Puesta en marcha (una sola vez)

1. **Crea un repositorio** en GitHub y sube esta carpeta.
2. En el repo → **Settings → Secrets and variables → Actions → New repository secret**,
   añade las credenciales SMTP del correo desde el que se envían las alertas:
   - `SMTP_HOST`, `SMTP_PORT` (587 normal / 465 SSL), `SMTP_SECURE` (`false`/`true`),
     `SMTP_USER`, `SMTP_PASS`, `SMTP_FROM`.
3. Listo. El cron se lanza solo cada hora. Para probar ya: pestaña **Actions → Monitor de
   velocidad → Run workflow** (marca "report" para recibir un informe aunque todo esté bien).

## Destinatarios (rollout progresivo)

Se editan en `config.js` → `RECIPIENTS`. Ahora mismo **solo Alejandro** para pruebas.
Cuando valide, descomentar Javier; cuando Javier valide, descomentar el cliente.

## Que no cuente como visita real

El bot se identifica con la marca `ThaiSpaSpeedBot` en su User-Agent. Para excluirlo de
las estadísticas: en **GA4 → Administrar → Configuración de datos → Filtros de datos**,
crear un filtro que excluya el tráfico cuyo User-Agent contenga `ThaiSpaSpeedBot`.
(El bot nunca completa compras ni genera pedidos.)

## Coste / minutos de GitHub Actions

- **Repo público:** GitHub Actions es **gratis e ilimitado**. Recomendado.
- **Repo privado:** el plan gratuito da 2.000 min/mes; a razón horaria puede quedarse
  corto. Si se quiere privado, bajar a cada 2 h o reducir `RUNS_PER_CHECK` a 1 en `config.js`.

## Pruebas locales

```bash
npm install
node monitor.js --preview     # genera emails de ejemplo (preview-*.html), no envía
cp .env.example .env          # rellena SMTP y luego:
node monitor.js --report      # medición real + informe por email
```

---
Monitor de velocidad · [dorica.agency](https://dorica.agency/) · 2026
