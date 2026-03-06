#!/bin/bash

# Script de desarrollo para iniciar API y Frontend
# Uso: ./dev.sh

echo "================================================"
echo "  🚀 Iniciando Entorno de Desarrollo"
echo "================================================"
echo ""

# Función para manejar Ctrl+C
cleanup() {
    echo ""
    echo "================================================"
    echo "  🛑 Deteniendo servicios..."
    echo "================================================"
    kill $API_PID $FRONTEND_PID 2>/dev/null
    exit 0
}

trap cleanup INT TERM

# Verificar que estamos en el directorio correcto
if [ ! -d "api" ] || [ ! -d "frontend" ]; then
    echo "❌ Error: Ejecuta este script desde php-mysql-version/"
    echo "   Ejemplo: cd php-mysql-version && ./dev.sh"
    exit 1
fi

# Verificar que config.php existe
if [ ! -f "api/config.php" ]; then
    echo "❌ Error: api/config.php no encontrado"
    echo "   Copia config.example.php a config.php y configúralo:"
    echo "   cp api/config.example.php api/config.php"
    exit 1
fi

# Verificar que node_modules existe
if [ ! -d "frontend/node_modules" ]; then
    echo "⚠️  node_modules no encontrado. Instalando dependencias..."
    cd frontend
    npm install
    cd ..
fi

echo "✅ Configuración verificada"
echo ""

# Iniciar API Backend (PHP)
echo "================================================"
echo "  🔧 Iniciando API Backend (PHP)"
echo "================================================"
cd api
php -S localhost:8000 > ../api.log 2>&1 &
API_PID=$!
cd ..

# Esperar un momento para que PHP inicie
sleep 2

# Verificar que la API esté corriendo
if curl -s http://localhost:8000 > /dev/null 2>&1; then
    echo "✅ API corriendo en http://localhost:8000"
else
    echo "❌ Error: No se pudo iniciar la API"
    echo "   Verifica los logs en api.log"
    kill $API_PID 2>/dev/null
    exit 1
fi

echo ""

# Iniciar Frontend (React + Vite)
echo "================================================"
echo "  ⚛️  Iniciando Frontend (React + Vite)"
echo "================================================"
cd frontend
npm run dev > ../frontend.log 2>&1 &
FRONTEND_PID=$!
cd ..

# Esperar un momento para que Vite inicie
sleep 3

# Verificar que el frontend esté corriendo
if curl -s http://localhost:3000 > /dev/null 2>&1; then
    echo "✅ Frontend corriendo en http://localhost:3000"
else
    echo "⚠️  Frontend iniciando... (puede tomar unos segundos)"
fi

echo ""
echo "================================================"
echo "  ✨ Servicios Iniciados"
echo "================================================"
echo ""
echo "  📡 API Backend:  http://localhost:8000"
echo "  🌐 Frontend:     http://localhost:3000"
echo ""
echo "  📋 URLs útiles:"
echo "     Login:        http://localhost:3000/login"
echo "     Registro:     http://localhost:3000/register"
echo "     Dashboard:    http://localhost:3000/dashboard"
echo ""
echo "  📝 Logs:"
echo "     API:          tail -f api.log"
echo "     Frontend:     tail -f frontend.log"
echo ""
echo "  ⚠️  Para detener: Presiona Ctrl+C"
echo ""
echo "================================================"

# Mostrar logs en tiempo real
echo "Mostrando logs (Ctrl+C para detener todo):"
echo ""
tail -f api.log frontend.log 2>/dev/null
