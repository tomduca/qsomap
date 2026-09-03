# Características actuales

## Vistas

- `map-ssb.html`: contactos SSB, voz y CW.
- `map-digi.html`: contactos digitales.
- `map-qsl.html`: contactos confirmados por LoTW, identificados en el cache con `QSL_RCVD = Y` desde Clublog.

## Mapa

- Mapa base con Leaflet Providers.
- Posición del operador a partir de su grid.
- Marcadores pequeños coloreados por banda.
- Agrupación de marcadores superpuestos.
- Líneas locales de gran círculo por la ruta longitudinal más corta.
- Repetición horizontal del mapa para mostrar recorridos hacia Asia y el Pacífico.
- Redibujado de puntos y líneas al mover o ampliar el mapa.
- Popup con llamada, nombre, grid, QTH, DXCC, banda, fecha, frecuencia, modo, SIG y comentarios disponibles.

## Datos y sincronización

- Clublog es la fuente primaria configurada.
- LoTW puede actuar como fallback.
- HamQTH y Spothole se usan server-side para completar grids faltantes.
- `build_cache.php` conserva los datos normalizados en `data/qso_cache.json`.
- `sync_daily.sh` actualiza los datos mediante Cron.

## Inicialización manual

Desde el navegador, ejecutar en este orden:

1. `sync_clublog.php` y esperar a que termine.
2. `build_cache.php` y esperar a que termine.

Si Clublog falla:

1. `sync_lotw.php` y esperar a que termine.
2. `build_cache.php` y esperar a que termine.

## Archivos de administración

- `sync_clublog.php`: descarga y normaliza QSOs de Clublog.
- `sync_lotw.php`: descarga y normaliza QSOs de LoTW.
- `build_cache.php`: completa grids y construye el cache para el navegador.
- `sync_daily.sh`: ejecuta la sincronización diaria desde Cron.
- `config.json`: credenciales y configuración privada.
- `data/qso_cache.json`: datos consumidos por las vistas.

No se requiere SSH para la instalación o la operación prevista con File Manager, navegador y Cron de cPanel.
