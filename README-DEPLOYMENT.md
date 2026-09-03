# Deployment de QSO Map

Esta guía usa File Manager, navegador y Cron de cPanel. No requiere SSH.

## Archivos principales

- `map-ssb.html`: vista SSB y CW.
- `map-digi.html`: vista de modos digitales.
- `map-qsl.html`: vista de QSOs confirmados por LoTW mediante `QSL_RCVD = Y`.
- `sync_clublog.php`: sincronización desde Clublog.
- `sync_lotw.php`: sincronización alternativa desde LoTW.
- `build_cache.php`: genera `data/qso_cache.json` con los grids disponibles.
- `sync_daily.sh`: orquestador utilizado por Cron.
- `config.json`: configuración privada y credenciales.

## Instalación manual

1. Sube el contenido a `public_html/qsomap/` desde File Manager.
2. Copia `config.json.example` como `config.json` y completa los datos privados.
3. Abre desde el navegador `sync_clublog.php` y espera a que termine.
4. Después abre `build_cache.php` y espera a que termine.
5. Si Clublog no está disponible, abre `sync_lotw.php` y después `build_cache.php`.
6. Verifica que existan `data/qso_data.json` y `data/qso_cache.json`.
7. Prueba las tres páginas `map-ssb.html`, `map-digi.html` y `map-qsl.html`.

No ejecutes dos procesos al mismo tiempo. `build_cache.php` siempre debe ejecutarse después de una sincronización terminada.

## Cron diario

En cPanel crea una tarea diaria a las 02:00. Para esta instalación:

```cron
cd /home/lu2met/public_html/qsomap && /bin/bash sync_daily.sh
```

Para otro hosting, reemplaza `/home/lu2met` por la ruta absoluta correspondiente.

`sync_daily.sh` ejecuta Clublog, usa LoTW como fallback y reconstruye el cache. El log se escribe en `sync_daily.log` dentro de `public_html/qsomap/`.

El archivo `sync_daily.sh` debe tener permisos 755 y la carpeta `data/` debe ser escribible.

## Seguridad

`config.json` contiene credenciales y no debe publicarse ni exponerse al navegador. Las URLs PHP de sincronización deben usarse solo durante la administración inicial o una resincronización manual; si el hosting lo permite, protégelas con autenticación o restricción de acceso.
