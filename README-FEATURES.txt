# Características actuales

## Vistas interactivas

- `map-ssb.html`: contactos de voz y CW.
- `map-digi.html`: modos digitales.
- `map-qsl.html`: QSOs confirmados (`QSL_RCVD = Y`).
- Leaflet permite mover y ampliar el mapa; los puntos se agrupan y las trayectorias se vuelven a dibujar con la vista.
- Los puntos se colorean por banda y los popups muestran los datos disponibles del QSO y DXCC.

Estas páginas consumen `data/qso_cache.json`, utilizan `js/simple-map.js`, `css/simple-map.css` y `data/dxcc.js`, y cargan Leaflet, Leaflet Providers y OverlappingMarkerSpiderfier desde CDN.

## Mapa estático para QRZ

- `map-ssb-qrz.php` genera una página de 600 x 600 px sin JavaScript.
- Lee los QSOs enriquecidos de `data/qso_cache.json` y calcula en cada solicitud las coordenadas y rutas de gran círculo.
- La longitud se centra en el QTH y la latitud usa Web Mercator, limitada a +/-85.0511288 grados.
- Usa `background_lu2met_1x1.png`, generado por `make_bg_1x1.php` a partir de `map600x600.png` y el grid de `config.json`.
- Las respuestas PHP envían cabeceras anti-caché; el PNG lleva un parámetro único. En QRZ, agrega también `?v=...` al URL del iframe y actualízalo al publicar cambios.
- Un clic sobre el mapa abre la vista interactiva SSB.

Genera el fondo al instalar o actualizar el generador y cuando cambie el QTH o el planisferio. `php make_bg_1x1.php` requiere PHP GD y no forma parte de `sync_daily.sh`.

## Datos y sincronización

- `sync_clublog.php` descarga QSOs de Clublog; `sync_lotw.php` es una fuente alternativa.
- Ambos producen `data/qso_data.json`.
- `build_cache.php` completa grids faltantes con HamQTH/Spothole y escribe `data/qso_cache.json`.
- `sync_daily.sh` automatiza la sincronización y reconstrucción del caché mediante Cron.

Flujo manual: Clublog (o LoTW si hace falta), luego `build_cache.php`. No ejecutes las sincronizaciones en paralelo.
