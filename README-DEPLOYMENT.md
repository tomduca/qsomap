# Despliegue de QSO Map

Guía para publicar desde File Manager y operar con navegador y Cron.

## Componentes

- Mapas interactivos: `map-ssb.html`, `map-digi.html`, `map-qsl.html`, `js/simple-map.js`, `css/simple-map.css` y `data/dxcc.js`.
- Mapa estático QRZ: `map-ssb-qrz.php`, `background_lu2met_1x1.png` y `data/qso_cache.json`.
- Generador del fondo: `make_bg_1x1.php`, que usa `map600x600.png` y `config.json`.
- Sincronización: `sync_clublog.php`, `sync_lotw.php`, `build_cache.php` y `sync_daily.sh`.

## Instalación y datos

1. Sube el proyecto a `public_html/qsomap/`, incluyendo las tres vistas interactivas y sus carpetas `js/`, `css/`, `data/`, `img/` y `fa/`.
2. Copia `config.json.example` como `config.json` y configura credenciales, `map.my_callsign` y `map.my_grid`. No publiques `config.json`.
3. Asegúrate de que `data/` permita escritura.
4. Ejecuta `sync_clublog.php` y espera a que termine; luego ejecuta `build_cache.php`. Si Clublog falla, ejecuta `sync_lotw.php` y luego `build_cache.php`.
5. Comprueba que existan `data/qso_data.json` y `data/qso_cache.json`; abre las tres páginas interactivas.

No ejecutes procesos de sincronización en paralelo.

## Generar el fondo estático

El fondo activo es cuadrado, de 600 x 600. `make_bg_1x1.php` usa el planisferio local `map600x600.png` y el grid configurado en `config.json`; genera `background_lu2met_1x1.png`. Requiere PHP con GD. Ejecútalo al instalar o actualizar el generador y cuando cambie el QTH o el planisferio, no en el Cron diario:

```bash
php make_bg_1x1.php
```

Si generas el fondo en otra máquina, sube `background_lu2met_1x1.png` al directorio raíz del sitio. `map-ssb-qrz.php` recalcula las posiciones y las rutas al cargar y lee `data/qso_cache.json`; no requiere regenerar el fondo cuando solo cambian los QSOs.

Sin acceso a consola, ejecuta el PHP desde el navegador del hosting abriendo `https://tu-dominio.com/qsomap/make_bg_1x1.php` y verifica que el archivo PNG se haya actualizado.

## Iframe para QRZ

Usa un iframe cuadrado. Conserva un parámetro `v` en el URL y cámbialo al publicar cambios para forzar a QRZ a solicitar la versión actual:

```html
<iframe src="https://tu-dominio.com/qsomap/map-ssb-qrz.php?v=20261009-1" width="600" height="600" frameborder="0" scrolling="no"></iframe>
```

El PHP envía cabeceras anti-caché; además, el URL del PNG de fondo lleva un valor único en cada respuesta. Abre exactamente el URL del `src` en una pestaña para comprobar la versión servida. Al hacer clic en el mapa, se abre la vista interactiva SSB en otra pestaña.

## Cron diario

En cPanel programa `sync_daily.sh` una vez al día, por ejemplo a las 02:00:

```cron
cd /home/TU_USUARIO/public_html/qsomap && /bin/bash sync_daily.sh
```

El script sincroniza datos, usa LoTW como fallback si Clublog falla, reconstruye `data/qso_cache.json` y escribe `sync_daily.log`. Dale permiso 755 al script y confirma que `data/` sea escribible. El generador del PNG estático no forma parte de este flujo.

## Seguridad

`config.json` contiene credenciales. No lo publiques ni lo expongas desde el sitio. Protege las rutas de sincronización si el hosting permite restringirlas.
