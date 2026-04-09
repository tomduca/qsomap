#!/bin/bash
# Script para inicializar QSO Map en producción

echo "=== Inicializando QSO Map en producción ==="

# Verificar que estamos en el directorio correcto
if [ ! -f "config.json" ]; then
    echo "Error: config.json no encontrado. Ejecutá este script desde el directorio qsomap/"
    exit 1
fi

# Crear directorio data si no existe
mkdir -p data
chmod 755 data

echo "1. Sincronizando QSOs desde Clublog..."
php sync_clublog.php

if [ $? -ne 0 ]; then
    echo "   Clublog falló, intentando LOTW..."
    php sync_lotw.php
fi

echo "2. Construyendo cache con grids desde HamQTH..."
php build_cache.php

echo "3. Verificando archivos generados..."
if [ -f "data/qso_cache.json" ]; then
    echo "   ✓ qso_cache.json creado"
    ls -lh data/qso_cache.json
else
    echo "   ✗ ERROR: qso_cache.json no fue creado"
    exit 1
fi

echo ""
echo "=== Inicialización completa ==="
echo "El mapa debería estar funcionando en: https://lu2met.ar/qsomap/index-headless.html"
