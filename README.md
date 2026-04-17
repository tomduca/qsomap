# QSO Map - Automated Sync Edition

Sistema automatizado de visualización de QSOs en mapa interactivo con sincronización automática desde Clublog/LOTW.

**Basado en:** [QSO Map](https://git.ianrenton.com/ian/qsomap.git) por Ian Renton (M0TRT)  
**Licencia:** MIT  
**Adaptado para:** LU2MET

## 🎯 Características Principales

### Sincronización Automática
- ✅ Sync diario desde Clublog (fuente primaria)
- ✅ Fallback a LOTW si Clublog falla
- ✅ Margen de seguridad de 2 días para no perder QSOs
- ✅ Detecta y sincroniza QSOs editados recientemente
- ✅ Cache optimizado (instantáneo - skip lookups innecesarios)

### Gestión Inteligente de Grids
- ✅ Respeta grids manuales de RUMlogNG/Clublog
- ✅ Lookup automático solo para QSOs sin grid (HamQTH → Spothole)
- ✅ Los grids de Clublog NUNCA se sobrescriben
- ✅ Grids corregidos se actualizan automáticamente
- ⚡ Optimizado: skip lookups cuando grid ya existe (99% de casos)

### Visualización
- ✅ Marcadores en colores por banda (PSK Reporter scheme)
- ✅ Líneas geodésicas coloreadas
- ✅ Símbolos xOTA para activaciones POTA/SOTA
- ✅ Popups con información completa del QSO
- ✅ Carga instantánea desde cache JSON

### Portabilidad
- ✅ Scripts con paths relativos (funcionan en cualquier hosting)
- ✅ Compatible con cPanel y hosting compartido
- ✅ No requiere SSH para deployment
- ✅ Logs locales (no requiere permisos especiales)

## 📦 Instalación Rápida

Ver [DEPLOYMENT-CHECKLIST.txt](DEPLOYMENT-CHECKLIST.txt) para instrucciones completas paso a paso.

## 🚀 Deployment

### Requisitos
- Hosting con PHP 7.4+ y cPanel
- Credenciales de Clublog (API key + application password)
- Credenciales de HamQTH (opcional, para lookup de grids)

### Proceso de Instalación

1. **Subir archivos**
   ```bash
   # Descomprimir qsomap-clean-deploy.tar.gz
   # Subir a public_html/qsomap/ vía FTP/File Manager
   ```

2. **Configurar permisos**
   ```bash
   chmod 755 data/
   chmod 755 setup_initial.sh
   chmod 755 sync_daily.sh
   ```

3. **Editar config.json**
   - Agregar credenciales de Clublog
   - Agregar credenciales de HamQTH
   - Configurar QTH (callsign, grid, lat/lon)

4. **Inicialización (una vez)**
   ```bash
   # Crear cron temporal en cPanel:
   cd /home/USUARIO/public_html/qsomap && /bin/bash setup_initial.sh > /home/USUARIO/qsomap_init.log 2>&1
   ```

5. **Sync diario (permanente)**
   ```bash
   # Cron a las 2 AM:
   cd /home/USUARIO/public_html/qsomap && /bin/bash sync_daily.sh
   ```

Ver documentación completa en:
- [HOSTING-SETUP.txt](HOSTING-SETUP.txt) - Guía de instalación
- [DEPLOYMENT-CHECKLIST.txt](DEPLOYMENT-CHECKLIST.txt) - Checklist paso a paso
- [CRON-SETUP.txt](CRON-SETUP.txt) - Configuración de cron
- [README-FEATURES.txt](README-FEATURES.txt) - Características y mejoras

## 🔧 Uso

### Visualización
- **Mapa completo:** `https://tu-dominio.com/qsomap/index-headless.html`
- **Mapa QSL:** `https://tu-dominio.com/qsomap/qsl.html`

### Actualización de Grids
1. Editar QSO en RUMlogNG
2. RUMlogNG sincroniza con Clublog
3. Esperar al sync diario (2 AM) o forzar manualmente
4. El mapa se actualiza automáticamente

### Sync Manual
```bash
cd ~/public_html/qsomap
bash sync_daily.sh
```

### Sync Completo (después de editar grids viejos)
```bash
cd ~/public_html/qsomap
rm -f data/clublog_last_sync.txt
bash sync_daily.sh
```

### Deployment de Mapa Confirmado (primera vez)
Después de subir archivos nuevos:
```bash
cd ~/public_html/qsomap
php sync_clublog.php          # Re-sync con campos de confirmación
rm data/qso_cache.json         # Eliminar cache viejo
php build_cache.php            # Reconstruir cache completo
```

Luego acceder a: `https://tu-dominio.com/qsomap/qsl.html`

**Nota:** El sync diario automático ya incluye los campos de confirmación, por lo que después del primer deployment manual, todo se actualiza automáticamente.

## 📊 Arquitectura

### Flujo de Datos
```
RUMlogNG → Clublog → sync_clublog.php → qso_data.json
                                       ↓
                              build_cache.php → qso_cache.json
                                       ↓
                              index-headless.html (mapa)
```

### Prioridad de Datos
1. **Clublog** (máxima) - Grids manuales de RUMlogNG
2. **Cache** (media) - Lookups previos reutilizados
3. **HamQTH/Spothole** (baja) - Solo si falta grid

### Archivos Importantes
- `sync_daily.sh` - Script de sync diario
- `sync_clublog.php` - Descarga de Clublog
- `build_cache.php` - Construcción de cache
- `data/qso_cache.json` - Cache usado por el mapa
- `sync_daily.log` - Log de sync diario

## 🎨 Mejoras sobre el Original

### Problemas Resueltos
1. ✅ **Marcadores negros** → Normalización de nombres de banda
2. ✅ **Pérdida de QSOs** → Margen de 2 días en sync
3. ✅ **Grids incorrectos** → Prioridad Clublog > Lookups
4. ✅ **Sync lento** → Cache incremental
5. ✅ **Scripts no portables** → Paths relativos
6. ⚡ **Cache build lento** → Skip lookups cuando grid existe (2 vs 132 lookups)

Ver [README-FEATURES.txt](README-FEATURES.txt) para detalles completos.

## 📝 Créditos

**Proyecto Original:** [QSO Map](https://git.ianrenton.com/ian/qsomap.git) por Ian Renton (M0TRT)

**Mejoras y Adaptaciones:** Tomás Duca para LU2MET

Ver [CREDITS.txt](CREDITS.txt) para atribuciones completas.

## 📄 Licencia

MIT License - Ver [CREDITS.txt](CREDITS.txt) para texto completo.

## 🔗 Enlaces

- **Repositorio Original:** https://git.ianrenton.com/ian/qsomap.git
- **Demo Original:** https://qsomap.m0trt.radio
- **Este Fork:** https://github.com/tomduca/qsomap

## 📞 Contacto

- **Callsign:** LU2MET
- **GitHub:** https://github.com/tomduca/qsomap
