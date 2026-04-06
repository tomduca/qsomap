# QSO Map - Deployment Guide

## Sistema Implementado

### Componentes
- **sync_lotw.php** - Sincroniza QSOs desde LOTW (incremental)
- **build_cache.php** - Construye cache con lookups de grids (HamQTH)
- **index-headless.html** - Mapa sin UI para embedding
- **config.json** - Credenciales (protegido por .htaccess)

### Configuración Actual
- **Callsign**: LU2MET
- **Grid**: FF57oc
- **QSOs**: 285 desde LOTW
- **Grids**: 98 (34% cobertura vía HamQTH)

## Instalación en Producción

### 1. Subir Archivos
Subir todo el contenido a tu hosting:
```bash
scp -r qsomap/ usuario@hosting:/path/to/webroot/
```

### 2. Configurar Permisos
```bash
chmod 755 sync_lotw.php build_cache.php
chmod 644 config.json
chmod 755 data/
chmod 644 data/*.json
```

### 3. Verificar .htaccess
Asegurar que Apache tiene mod_rewrite y mod_headers habilitados.

### 4. Configurar Cron (Sync Diario)
Agregar a crontab:
```cron
0 2 * * * /path/to/qsomap/sync_daily.sh
```

Esto ejecutará el sync todos los días a las 2 AM.

### 5. Primera Sincronización
```bash
cd /path/to/qsomap
php sync_lotw.php
php build_cache.php
```

## URLs

- **Mapa Headless**: `https://tudominio.com/qsomap/index-headless.html`
- **Mapa Normal**: `https://tudominio.com/qsomap/index.html`

## Embedding en QRZ.com

El mapa headless está configurado para ser embebido como iframe:

```html
<iframe src="https://tudominio.com/qsomap/index-headless.html" 
        width="100%" height="600" frameborder="0">
</iframe>
```

## Configuración del Mapa

El mapa headless tiene configuración hardcodeada en `js/headless-config.js`:
- Basemap: ESRI NatGeo World Map (50% opacity)
- Markers: Círculos pequeños (size 0.5)
- Colores: Por banda (PSK Reporter scheme)
- Líneas: Geodésicas, coloreadas, finas

## Troubleshooting

### Los QSOs no se actualizan
```bash
# Ejecutar manualmente
php sync_lotw.php
php build_cache.php
```

### Pocos QSOs con grid
- LOTW no incluye gridsquare en el ADIF
- HamQTH tiene cobertura limitada (~34%)
- Considerar agregar más fuentes de lookup

### Errores de permisos
```bash
chown -R www-data:www-data /path/to/qsomap/data/
chmod 755 /path/to/qsomap/data/
```

## Logs

Los logs del sync diario se guardan en:
```
/var/log/qsomap-sync.log
```

## Credenciales

Las credenciales están en `config.json` y protegidas por `.htaccess`.
**NUNCA** commitear este archivo a git ni exponerlo públicamente.

## Próximas Mejoras

1. **Clublog como fuente primaria** - Mejor cobertura de grids
2. **Colores de marcadores** - Actualmente muestran negro, necesita debugging adicional
3. **API adicionales** - Agregar más fuentes de grid lookup
4. **Monitoreo** - Alertas si el sync falla

## Soporte

Para problemas o mejoras, contactar al desarrollador.
