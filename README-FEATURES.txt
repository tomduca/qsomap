========================================
QSO MAP - CARACTERÍSTICAS Y MEJORAS
========================================

PROYECTO ORIGINAL:
- Autor: Ian Renton (https://ianrenton.com)
- Repositorio: https://git.ianrenton.com/ian/qsomap.git
- Licencia: MIT

Este documento describe las características implementadas y mejoras
realizadas al sistema QSO Map original de Ian Renton.

========================================
CARACTERÍSTICAS PRINCIPALES
========================================

1. SINCRONIZACIÓN AUTOMÁTICA
   - Sync diario con Clublog (fuente primaria)
   - Fallback a LOTW si Clublog falla
   - Margen de seguridad de 2 días para no perder QSOs
   - Detecta y sincroniza QSOs editados recientemente

2. GESTIÓN DE GRIDS
   - Respeta grids ingresados manualmente en RUMlogNG
   - Los grids de Clublog NUNCA se sobrescriben con lookups
   - Lookup automático solo para QSOs sin grid:
     * HamQTH (primario)
     * Spothole (fallback)
   - Grids corregidos se actualizan automáticamente

3. CACHE INCREMENTAL Y OPTIMIZADO
   - Primera construcción: ~10 segundos (optimizado)
   - Syncs subsiguientes: ~5 segundos (solo QSOs nuevos/modificados)
   - Reutiliza datos de cache cuando no hay cambios
   - Detecta cambios en datos de Clublog automáticamente
   - Skip automático de lookups cuando grid ya existe (99% de QSOs)

4. VISUALIZACIÓN
   - Marcadores en colores por banda (PSK Reporter scheme)
   - Líneas geodésicas coloreadas
   - Símbolos xOTA para activaciones
   - Popups con información completa del QSO
   - Carga instantánea desde cache JSON
   - Mapa de QSOs confirmados (solo LoTW/Clublog)

5. PORTABILIDAD
   - Scripts con paths relativos (funcionan en cualquier hosting)
   - Logs locales (no requiere permisos en /var/log)
   - Auto-detección de directorio de trabajo
   - Compatible con cPanel y hosting compartido

========================================
MEJORAS IMPLEMENTADAS
========================================

PROBLEMA 1: Marcadores negros en lugar de colores
SOLUCIÓN: Normalización de nombres de banda a lowercase
RESULTADO: Todos los marcadores muestran colores correctos por banda

PROBLEMA 2: Pérdida de QSOs por timing
SOLUCIÓN: Margen de seguridad de 2 días en sync incremental
RESULTADO: No se pierden QSOs por diferencias de zona horaria o delays

PROBLEMA 3: Grids incorrectos para operaciones portables
SOLUCIÓN: Prioridad de datos Clublog > Cache > Lookups
RESULTADO: Grids manuales de RUMlogNG siempre se respetan
EJEMPLO: CE0Y/DJ4EL (Easter Island) mantiene grid DG52GU correcto

PROBLEMA 4: Sync lento (3+ minutos cada vez)
SOLUCIÓN: Cache incremental que solo procesa cambios
RESULTADO: Sync diario en ~10 segundos vs 3+ minutos

PROBLEMA 5: Scripts no funcionan en hosting
SOLUCIÓN: Paths relativos y auto-detección de directorio
RESULTADO: Scripts portables que funcionan en cualquier entorno

PROBLEMA 6: Cache build lento (lookups innecesarios)
SOLUCIÓN: Skip lookups cuando grid ya existe en Clublog/cache
RESULTADO: Cache build instantáneo (2 lookups vs 132 antes)

========================================
FLUJO DE DATOS
========================================

1. INGRESO DE QSO
   RUMlogNG → Clublog
   (Grid manual ingresado por usuario)

2. SYNC DIARIO (2 AM)
   Clublog API → sync_clublog.php
   - Descarga últimos 2 días + QSOs editados
   - Guarda en data/qso_data.json
   - Actualiza clublog_last_sync.txt

3. CONSTRUCCIÓN DE CACHE
   build_cache.php procesa:
   - QSOs con grid de Clublog: usa directo (sin lookup) ⚡
   - QSOs existentes sin cambios: reutiliza cache
   - QSOs sin grid: lookup (HamQTH/Spothole) - solo ~1% de casos
   - Resultado: data/qso_cache.json (construcción instantánea)

4. VISUALIZACIÓN
   index-headless.html carga:
   - qso_cache.json (todos los QSOs con grids)
   - Renderiza mapa con colores por banda
   - Muestra líneas geodésicas
   - Popups con info completa

========================================
PRIORIDAD DE DATOS
========================================

Para cada campo (grid, name, qth):

1. CLUBLOG (máxima prioridad)
   - Datos ingresados manualmente en RUMlogNG
   - Sincronizados a Clublog
   - NUNCA se sobrescriben

2. CACHE EXISTENTE (segunda prioridad)
   - Lookups previos de HamQTH/Spothole
   - Se reutilizan si Clublog no tiene datos
   - Se actualizan si Clublog trae datos nuevos

3. LOOKUP NUEVO (última prioridad)
   - Solo si Clublog y cache están vacíos
   - HamQTH primero, Spothole como fallback
   - Se guarda en cache para futuros syncs

========================================
MANTENIMIENTO
========================================

SYNC DIARIO AUTOMÁTICO
- Configurado en cron para 2 AM
- No requiere intervención manual
- Log en: public_html/qsomap/sync_daily.log

EDICIÓN DE GRIDS
1. Editá el QSO en RUMlogNG
2. RUMlogNG sincroniza con Clublog
3. Esperá al sync diario (2 AM)
4. El mapa se actualiza automáticamente

SYNC MANUAL (si es necesario)
cd ~/public_html/qsomap
bash sync_daily.sh

SYNC COMPLETO (después de editar grids viejos)
cd ~/public_html/qsomap
rm -f data/clublog_last_sync.txt
bash sync_daily.sh

========================================
MAPA DE QSOs CONFIRMADOS
========================================

DESCRIPCIÓN:
- Mapa separado que muestra solo QSOs confirmados en LoTW o Clublog
- Mismo diseño y funcionalidad que el mapa principal
- Agrupa por CALL-GRID (muestra primer QSO confirmado por estación)

ARCHIVOS:
- qsl.html - Mapa de QSL
- js/headless-config-confirmed.js - Lógica de filtrado

CRITERIOS DE CONFIRMACIÓN:
- QSL_RCVD = 'Y' (confirmado en Clublog)
- LOTW_QSL_RCVD = 'Y' (confirmado en LoTW)
- Se muestra si cumple cualquiera de los dos

LÓGICA DE AGRUPACIÓN:
- Agrupa QSOs por CALL-GRID (callsign + gridsquare del corresponsal)
- Si hay múltiples QSOs confirmados con la misma estación desde el mismo grid:
  * Se muestra el primer QSO confirmado (más antiguo)
- Si la estación opera portable desde otro grid:
  * Se muestra como marcador separado

EJEMPLO:
- QSO 1: W2EUA en EL97UD el 2026-04-16 (confirmado) → SE MUESTRA
- QSO 2: W2EUA en EL97UD el 2026-04-20 (confirmado) → NO SE MUESTRA (duplicado)
- QSO 3: W2EUA en FM19MA el 2026-04-21 (confirmado) → SE MUESTRA (grid diferente)

DEPLOYMENT EN CPANEL:
Después de subir archivos nuevos/modificados:

1. Re-sincronizar desde Clublog (para obtener campos de confirmación):
   cd ~/public_html/qsomap
   php sync_clublog.php

2. Eliminar cache viejo y reconstruir:
   rm data/qso_cache.json
   php build_cache.php

3. Verificar mapa QSL:
   https://tu-dominio.com/qsomap/qsl.html

NOTA: El sync diario automático (2 AM) ya incluye los campos de confirmación,
por lo que después del primer deployment manual, todo se actualiza automáticamente.

========================================
ARCHIVOS IMPORTANTES
========================================

SCRIPTS:
- setup_initial.sh - Inicialización (una vez)
- sync_daily.sh - Sync diario (cron)
- sync_clublog.php - Descarga de Clublog (incluye campos de confirmación)
- build_cache.php - Construcción de cache (incluye QSL_RCVD, LOTW_QSL_RCVD)

DATOS:
- data/qso_data.json - QSOs raw de Clublog
- data/qso_cache.json - Cache con grids (usado por mapa)
- data/clublog_last_sync.txt - Fecha de último sync

LOGS:
- sync_daily.log - Log de sync diario
- qsomap_init.log - Log de inicialización (en home)

CONFIGURACIÓN:
- config.json - Credenciales (protegido por .htaccess)
- .htaccess - Protección y headers de iframe

DOCUMENTACIÓN:
- HOSTING-SETUP.txt - Guía de instalación
- DEPLOYMENT-CHECKLIST.txt - Checklist paso a paso
- CRON-SETUP.txt - Configuración de cron
- README-FEATURES.txt - Este archivo

========================================
SEGURIDAD
========================================

- config.json protegido por .htaccess (403 Forbidden)
- Credenciales nunca expuestas al navegador
- API keys solo usadas server-side
- Iframe embedding habilitado para QRZ.com
- Logs no contienen información sensible

========================================
COMPATIBILIDAD
========================================

HOSTING:
- cPanel con PHP 7.4+
- Hosting compartido (sin SSH requerido)
- Permisos estándar (755 para scripts y data/)

NAVEGADORES:
- Chrome/Edge (recomendado)
- Firefox
- Safari (usar modo incognito para evitar cache)

APIs:
- Clublog API (primaria)
- LOTW (fallback)
- HamQTH (grid lookup)
- Spothole (grid fallback)

========================================
CRÉDITOS
========================================

Este proyecto está basado en QSO Map de Ian Renton.
Ver CREDITS.txt para información completa de atribuciones.

Repositorio original: https://git.ianrenton.com/ian/qsomap.git
Licencia: MIT

========================================
