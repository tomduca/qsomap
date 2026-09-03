# QSO Map

Visor simple de QSOs para hosting PHP, con sincronización desde Clublog o LoTW y tres vistas de mapa.

El proyecto se inspira en [QSO Map](https://git.ianrenton.com/ian/qsomap.git) por Ian Renton. Las páginas actuales del visor simple no cargan código desde ese proyecto; los enlaces que aparecen en la documentación de créditos son únicamente atribuciones.

## Vistas

- `map-ssb.html`: contactos de voz y CW.
- `map-digi.html`: contactos digitales.
- `map-qsl.html`: contactos confirmados por LoTW, identificados en el cache con `QSL_RCVD = Y` desde Clublog.

Las tres vistas muestran el mapa base, la posición del operador, marcadores coloreados por banda, líneas de trayectoria y agrupación de marcadores superpuestos. Los popups incluyen la información disponible en `data/qso_cache.json`.

## Instalación

Requisitos:

- Hosting con PHP 7.4 o superior.
- Acceso a cron para la sincronización automática.
- Credenciales de las fuentes que se quieran utilizar.

1. Copiar `config.json.example` como `config.json`.
2. Completar las credenciales y el callsign en `config.json`.
3. Subir el proyecto al hosting.
4. Ejecutar desde el navegador los PHP de inicialización en el orden indicado abajo.
5. Configurar `sync_daily.sh` como tarea cron.

El archivo `config.json` contiene secretos y está excluido de Git. No debe publicarse.

## Sincronización

El flujo es:

```text
Clublog -> sync_clublog.php -> data/qso_data.json
LoTW    -> sync_lotw.php    -> data/qso_data.json
                              |
                       build_cache.php
                              |
                       data/qso_cache.json
                              |
                       map-*.html
```

`build_cache.php` conserva grids existentes y puede consultar HamQTH y Spothole cuando falta un grid. Las credenciales de Clublog, LoTW y HamQTH se leen desde `config.json`.

Sincronización manual:

```bash
bash sync_daily.sh
```

Inicialización desde File Manager y navegador:

1. Subir y completar `config.json`.
2. Abrir `https://tu-dominio.com/qsomap/sync_clublog.php` y esperar a que finalice.
3. Abrir `https://tu-dominio.com/qsomap/build_cache.php` y esperar a que finalice.
4. Verificar que existan `data/qso_data.json` y `data/qso_cache.json`.

Si Clublog no está disponible, abrir primero `sync_lotw.php` y después `build_cache.php`.

No ejecutar dos PHP simultáneamente: `build_cache.php` necesita que la descarga anterior haya terminado.

Documentación operativa:

- [DEPLOYMENT-CHECKLIST.txt](DEPLOYMENT-CHECKLIST.txt)
- [HOSTING-SETUP.txt](HOSTING-SETUP.txt)
- [CRON-SETUP.txt](CRON-SETUP.txt)
- [README-DEPLOYMENT.md](README-DEPLOYMENT.md)

## Parametrización futura

La parametrización completa es posible y conviene hacerla antes de publicar el repositorio como plantilla. Actualmente quedan hardcodeados en `js/simple-map.js`:

- Callsign del operador: `LU2MET`.
- Grid del operador: `FF57oc`.
- Ruta del cache: `data/qso_cache.json`.
- Proveedor y configuración inicial del mapa.

La primera fase recomendada sería agregar una sección pública `map` a `config.json.example` y generar un archivo frontend sin secretos, por ejemplo `data/map-config.json`, con solo `callsign`, `grid`, `cache_file`, `default_basemap` y colores. El visor leería ese archivo antes de cargar el cache.

Las credenciales deben permanecer exclusivamente en `config.json` del servidor y nunca enviarse al navegador. La fuente de datos debería controlarse mediante opciones backend (`clublog.enabled`, `lotw.enabled`, `hamqth.enabled`, `spothole.enabled`), manteniendo un formato normalizado único para el cache. Así otro operador solo tendría que editar su configuración, ejecutar la sincronización y publicar las páginas de mapa.

Esta parametrización está documentada como diseño futuro; todavía no está implementada.

## Créditos y licencia

Las atribuciones del proyecto original están en [CREDITS.txt](CREDITS.txt). El proyecto utiliza licencia MIT.
