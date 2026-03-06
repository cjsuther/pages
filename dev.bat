@echo off
REM Script de desarrollo para Windows
REM Uso: dev.bat

echo ================================================
echo   Iniciando Entorno de Desarrollo
echo ================================================
echo.

REM Verificar que estamos en el directorio correcto
if not exist "api\" (
    echo Error: Ejecuta este script desde php-mysql-version\
    echo Ejemplo: cd php-mysql-version ^&^& dev.bat
    pause
    exit /b 1
)

if not exist "frontend\" (
    echo Error: Carpeta frontend no encontrada
    pause
    exit /b 1
)

REM Verificar que config.php existe
if not exist "api\config.php" (
    echo Error: api\config.php no encontrado
    echo Copia config.example.php a config.php y configuralo:
    echo copy api\config.example.php api\config.php
    pause
    exit /b 1
)

REM Verificar que node_modules existe
if not exist "frontend\node_modules\" (
    echo node_modules no encontrado. Instalando dependencias...
    cd frontend
    call npm install
    cd ..
)

echo Configuracion verificada
echo.

echo ================================================
echo   Iniciando API Backend (PHP)
echo ================================================
cd api
start "API Backend" cmd /k "php -S localhost:8000"
cd ..

timeout /t 2 /nobreak >nul

echo API corriendo en http://localhost:8000
echo.

echo ================================================
echo   Iniciando Frontend (React + Vite)
echo ================================================
cd frontend
start "Frontend React" cmd /k "npm run dev"
cd ..

echo.
echo ================================================
echo   Servicios Iniciados
echo ================================================
echo.
echo   API Backend:  http://localhost:8000
echo   Frontend:     http://localhost:3000
echo.
echo   URLs utiles:
echo      Login:        http://localhost:3000/login
echo      Registro:     http://localhost:3000/register
echo      Dashboard:    http://localhost:3000/dashboard
echo.
echo   Para detener: Cierra las ventanas de consola
echo.
echo ================================================
echo.
echo Presiona cualquier tecla para abrir el navegador...
pause >nul

start http://localhost:3000

echo.
echo Presiona cualquier tecla para salir...
pause >nul
