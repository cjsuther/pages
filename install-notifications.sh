#!/bin/bash

###############################################################################
# Script de instalación del Sistema de Notificaciones
#
# Este script automatiza la instalación del sistema de notificaciones,
# incluyendo dependencias, migración de base de datos y configuración.
#
# Uso: bash install-notifications.sh
###############################################################################

set -e  # Salir si hay algún error

echo "=============================================="
echo "INSTALACIÓN DEL SISTEMA DE NOTIFICACIONES"
echo "=============================================="
echo ""

# Colores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Función para imprimir mensajes
print_success() {
    echo -e "${GREEN}✓ $1${NC}"
}

print_error() {
    echo -e "${RED}✗ $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠ $1${NC}"
}

print_info() {
    echo -e "${YELLOW}ℹ $1${NC}"
}

# Verificar que estamos en el directorio correcto
if [ ! -f "migration_add_notifications_system.sql" ]; then
    print_error "No se encontró migration_add_notifications_system.sql"
    print_info "Asegúrate de estar en el directorio php-mysql-version/"
    exit 1
fi

print_success "Directorio correcto verificado"

# Paso 1: Verificar que existe Composer
echo ""
echo "[Paso 1/5] Verificando Composer..."
if ! command -v composer &> /dev/null; then
    print_warning "Composer no está instalado"
    print_info "Instalando Composer..."

    # Descargar e instalar Composer
    php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
    php composer-setup.php --quiet
    rm composer-setup.php

    if [ -f "composer.phar" ]; then
        alias composer='php composer.phar'
        print_success "Composer instalado localmente"
    else
        print_error "No se pudo instalar Composer"
        exit 1
    fi
else
    print_success "Composer encontrado"
fi

# Paso 2: Instalar dependencias PHP
echo ""
echo "[Paso 2/5] Instalando dependencias PHP..."
cd api

if [ ! -f "composer.json" ]; then
    print_error "No se encontró composer.json"
    exit 1
fi

composer install --no-dev --optimize-autoloader

if [ $? -eq 0 ]; then
    print_success "Dependencias instaladas correctamente"
else
    print_error "Error al instalar dependencias"
    exit 1
fi

cd ..

# Paso 3: Configurar config.php
echo ""
echo "[Paso 3/5] Configurando archivos de configuración..."

if [ ! -f "api/config.php" ]; then
    if [ -f "api/config.example.php" ]; then
        cp api/config.example.php api/config.php
        print_success "Archivo config.php creado desde config.example.php"
        print_warning "IMPORTANTE: Debes editar api/config.php con tus credenciales"
    else
        print_error "No se encontró config.example.php"
        exit 1
    fi
else
    print_info "config.php ya existe"
fi

# Paso 4: Generar claves VAPID
echo ""
echo "[Paso 4/5] Generando claves VAPID..."

if [ -f "api/generate-vapid-keys.php" ]; then
    php api/generate-vapid-keys.php > vapid-keys.txt

    if [ $? -eq 0 ]; then
        print_success "Claves VAPID generadas"
        print_warning "Las claves se guardaron en vapid-keys.txt"
        print_info "Copia estas claves a api/config.php"
        echo ""
        cat vapid-keys.txt | grep "define"
        echo ""
    else
        print_error "Error al generar claves VAPID"
    fi
else
    print_error "No se encontró generate-vapid-keys.php"
fi

# Paso 5: Migración de base de datos
echo ""
echo "[Paso 5/5] Migración de base de datos..."
print_info "Para aplicar la migración, ejecuta uno de los siguientes comandos:"
echo ""
echo "Opción 1: Usando mysql CLI"
echo "  mysql -u tu_usuario -p tu_base_de_datos < migration_add_notifications_system.sql"
echo ""
echo "Opción 2: Desde phpMyAdmin"
echo "  1. Abre phpMyAdmin"
echo "  2. Selecciona tu base de datos"
echo "  3. Ve a la pestaña 'Importar'"
echo "  4. Sube el archivo migration_add_notifications_system.sql"
echo ""

# Instrucciones finales
echo ""
echo "=============================================="
echo "INSTALACIÓN COMPLETADA"
echo "=============================================="
echo ""
print_success "Dependencias instaladas"
print_success "Archivos de configuración creados"
print_success "Claves VAPID generadas"
echo ""
print_warning "PASOS PENDIENTES:"
echo ""
echo "1. Editar api/config.php con tus credenciales:"
echo "   - Configuración de base de datos"
echo "   - Claves VAPID (ver vapid-keys.txt)"
echo "   - Clave secreta de cron"
echo ""
echo "2. Ejecutar la migración de base de datos:"
echo "   mysql -u usuario -p base_de_datos < migration_add_notifications_system.sql"
echo ""
echo "3. Configurar el cron job diario:"
echo "   crontab -e"
echo "   Agregar: 0 9 * * * php $(pwd)/api/notifications/process-daily.php"
echo ""
echo "4. Probar la instalación:"
echo "   php api/test-notifications-system.php"
echo ""
echo "Para más información, consulta:"
echo "  - GUIA_NOTIFICACIONES.md"
echo "  - SISTEMA_NOTIFICACIONES_RESUMEN.md"
echo ""
echo "=============================================="
