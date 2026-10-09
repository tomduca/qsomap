# QSO Map

## Ejemplos en funcionamiento

- [Mapa interactivo SSB](https://lu2met.ar/qsomap/map-ssb.html)
- [Mapa estático para QRZ](https://lu2met.ar/qsomap/map-ssb-qrz.php)

Visor de QSOs para hosting PHP. Incluye tres mapas interactivos, sincronización de datos desde Clublog o LoTW y un mapa SSB estático para incrustar en QRZ.

El proyecto se inspira en [QSO Map](https://git.ianrenton.com/ian/qsomap.git) por Ian Renton. Las vistas actuales no cargan código desde ese proyecto; las referencias son atribuciones.

## Mapas

- `map-ssb.html`: QSOs de voz y CW.
- `map-digi.html`: modos digitales.
- `map-qsl.html`: QSOs confirmados (`QSL_RCVD = Y`).
- `map-ssb-qrz.php`: mapa SSB server-side de 600 x 600 px para QRZ, sin JavaScript.

Los mapas interactivos usan Leaflet, Leaflet Providers y OverlappingMarkerSpiderfier; muestran la posición del operador, marcadores por banda, trayectorias y popups. Los mapas HTML cargan `js/simple-map.js`, `css/simple-map.css`, `data/dxcc.js` y `data/qso_cache.json`.

El mapa PHP calcula los puntos y las rutas en cada solicitud con una proyección cuadrada: longitud centrada en el QTH y latitud Web Mercator, limitada a +/-85.0511288 grados. El fondo generado se muestra a 600 x 600 px. Al hacer clic en el mapa se abre la vista interactiva SSB.

## Datos

```text
Clublog -> sync_clublog.php -> data/qso_data.json --+
LoTW    -> sync_lotw.php    -> data/qso_data.json --+-> build_cache.php
                                                       -> data/qso_cache.json
                                                          |-> mapas interactivos
                                                          +-> map-ssb-qrz.php
```

`build_cache.php` completa grids faltantes con HamQTH y Spothole y conserva búsquedas ya realizadas. Las credenciales viven en `config.json`, creado desde `config.json.example`; no publiques ese archivo.

## Instalación y actualización de datos

Requisitos: hosting con PHP 7.4 o superior, extensiones necesarias para las fuentes configuradas y acceso a Cron si se desea sincronización diaria. Para generar el fondo se requiere PHP GD.

1. Copia `config.json.example` a `config.json` y completa las credenciales y `map.my_callsign` / `map.my_grid`.
2. Sube el proyecto al hosting y asegúrate de que `data/` permita escritura.
3. Ejecuta `sync_clublog.php` y luego `build_cache.php`. Si Clublog no está disponible, ejecuta `sync_lotw.php` y luego `build_cache.php`.
4. Configura `sync_daily.sh` en Cron para actualizar los datos.

No ejecutes sincronizaciones en paralelo. `build_cache.php` debe comenzar cuando termine la descarga.

## Fondo del mapa QRZ

`make_bg_1x1.php` es el único generador activo. Lee el planisferio local `map600x600.png` y el grid del operador en `config.json`; escribe `background_lu2met_1x1.png` (600 x 600). Ejecuta `php make_bg_1x1.php` al instalar o actualizar el generador, y cuando cambie el mapa base o el QTH. No forma parte de la sincronización diaria. Sube ambos PNG si el generador se ejecuta fuera del hosting.

`map-ssb-qrz.php` necesita el PNG generado y `data/qso_cache.json`. Configura el iframe con dimensiones cuadradas y una versión en la URL, que se puede cambiar al publicar actualizaciones:

```html
<iframe src="https://tu-dominio.com/qsomap/map-ssb-qrz.php?v=20261009-1" width="600" height="600" frameborder="0" scrolling="no"></iframe>
```

El PHP envía cabeceras anti-caché y agrega una versión única al URL del PNG de fondo. El parámetro `v` en el iframe ayuda a evitar que QRZ reutilice una versión previa del documento. Para comprobar una publicación, abre esa misma URL directamente.

## Documentación operativa

- [README-DEPLOYMENT.md](README-DEPLOYMENT.md): despliegue e iframe QRZ.
- [HOSTING-SETUP.txt](HOSTING-SETUP.txt): instalación en cPanel.
- [DEPLOYMENT-CHECKLIST.txt](DEPLOYMENT-CHECKLIST.txt): verificación de publicación.
- [CRON-SETUP.txt](CRON-SETUP.txt): sincronización automática.
- [README-FEATURES.txt](README-FEATURES.txt): resumen de funciones.

## Créditos y licencia

Las atribuciones están en [CREDITS.txt](CREDITS.txt). El proyecto utiliza licencia MIT.
