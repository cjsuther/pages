<?php

namespace Tests\Support;

use PDO;
use RuntimeException;

/**
 * Una MariaDB de verdad, levantada para la corrida y tirada al terminar.
 *
 * Los tests unitarios usan FakePdo, que reconoce las consultas por fragmentos
 * de texto: nunca se entera de si una tabla o una columna existen. Así llegó a
 * producción código que leía event_records cuando la tabla todavía no estaba
 * creada. Contra esta base las consultas corren de verdad, sobre el esquema que
 * arman database.sql y las migraciones.
 *
 * No toca ningún MySQL instalado: arranca un servidor propio en un directorio
 * temporal y un puerto libre. Si ya hay uno configurado en RZ_TEST_DB_HOST y
 * RZ_TEST_DB_PORT (por ejemplo en CI), usa ése.
 */
class BaseDescartable
{
    /** Mismo sql_mode que producción (MariaDB 11.8 en Hostinger), que no es estricto. */
    const SQL_MODE = 'NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION';

    private static $host;
    private static $puerto;
    private static $directorio;
    private static $pid;
    private static $motivo = null;

    /** true si hay un servidor disponible; si no, motivo() dice por qué. */
    public static function disponible()
    {
        if (self::$puerto !== null) {
            return true;
        }

        if (self::$motivo !== null) {
            return false;
        }

        try {
            self::arrancar();
            return true;
        } catch (RuntimeException $e) {
            self::$motivo = $e->getMessage();
            return false;
        }
    }

    public static function motivo()
    {
        return self::$motivo;
    }

    /** Conexión a una base nueva y vacía con ese nombre. */
    public static function baseNueva($nombre)
    {
        if (!self::disponible()) {
            throw new RuntimeException('No hay MariaDB para los tests: ' . self::$motivo);
        }

        $servidor = self::conectar(null);
        $servidor->exec("DROP DATABASE IF EXISTS `$nombre`");
        $servidor->exec("CREATE DATABASE `$nombre` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        return self::conectar($nombre);
    }

    /** Conexión con las mismas opciones que Database::connect() en producción. */
    public static function conectar($base)
    {
        $dsn = 'mysql:host=' . self::$host . ';port=' . self::$puerto . ($base ? ";dbname=$base" : '') . ';charset=utf8mb4';
        $db = new PDO($dsn, 'root', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $db->exec("SET SESSION sql_mode = '" . self::SQL_MODE . "'");
        // Producción corre PHP y MariaDB en UTC; la base descartable tomaría la
        // zona del sistema. Con zonas distintas, lo que escribe PHP y compara
        // NOW() —el vencimiento de una reserva— se corre varias horas.
        $db->exec("SET time_zone = '+00:00'");

        return $db;
    }

    // ------------------------------------------------------------ servidor

    private static function arrancar()
    {
        $host = getenv('RZ_TEST_DB_HOST');
        $puerto = getenv('RZ_TEST_DB_PORT');

        if ($host && $puerto) {
            self::$host = $host;
            self::$puerto = (int) $puerto;
            return;
        }

        $instalar = self::binario(['mariadb-install-db', 'mysql_install_db']);
        $servidor = self::binario(['mariadbd', 'mysqld']);

        if ($instalar === null || $servidor === null) {
            throw new RuntimeException('no se encontró mariadb-install-db/mariadbd (brew install mariadb)');
        }

        self::$directorio = sys_get_temp_dir() . '/rz-db-' . getmypid() . '-' . bin2hex(random_bytes(3));
        mkdir(self::$directorio . '/data', 0700, true);

        exec(
            escapeshellarg($instalar) . ' --datadir=' . escapeshellarg(self::$directorio . '/data')
            . ' --auth-root-authentication-method=normal --skip-test-db > '
            . escapeshellarg(self::$directorio . '/install.log') . ' 2>&1',
            $salida,
            $estado
        );

        if ($estado !== 0) {
            throw new RuntimeException('falló mariadb-install-db: ' . self::ultimasLineas('install.log'));
        }

        self::$host = '127.0.0.1';
        self::$puerto = self::puertoLibre();

        // El socket se crea aunque se use TCP, y una ruta de más de 103
        // caracteres lo impide. Relativa al directorio de trabajo, queda corta.
        $comando = 'cd ' . escapeshellarg(self::$directorio) . ' && exec ' . escapeshellarg($servidor)
            . ' --no-defaults'
            . ' --datadir=' . escapeshellarg(self::$directorio . '/data')
            . ' --bind-address=127.0.0.1 --port=' . self::$puerto
            . ' --socket=./s --pid-file=./pid --log-error=./error.log'
            . ' --innodb-buffer-pool-size=32M --innodb-log-file-size=8M --skip-log-bin'
            . ' > /dev/null 2>&1 & echo $!';

        self::$pid = (int) trim(shell_exec($comando));

        for ($i = 0; $i < 150; $i++) {
            $conexion = @fsockopen(self::$host, self::$puerto, $errno, $errstr, 0.2);
            if ($conexion) {
                fclose($conexion);
                register_shutdown_function([self::class, 'apagar']);
                return;
            }
            usleep(100000);
        }

        self::apagar();
        throw new RuntimeException('MariaDB no arrancó: ' . self::ultimasLineas('error.log'));
    }

    public static function apagar()
    {
        if (self::$pid) {
            posix_kill(self::$pid, SIGTERM);
            for ($i = 0; $i < 50 && @posix_kill(self::$pid, 0); $i++) {
                usleep(100000);
            }
            self::$pid = null;
        }

        if (self::$directorio && is_dir(self::$directorio)) {
            exec('rm -rf ' . escapeshellarg(self::$directorio));
            self::$directorio = null;
        }
    }

    private static function binario(array $nombres)
    {
        foreach ($nombres as $nombre) {
            $ruta = trim((string) shell_exec('command -v ' . escapeshellarg($nombre) . ' 2>/dev/null'));
            if ($ruta !== '') {
                return $ruta;
            }
        }

        return null;
    }

    private static function puertoLibre()
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $nombre = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($nombre, strrpos($nombre, ':') + 1);
    }

    private static function ultimasLineas($archivo)
    {
        $ruta = self::$directorio . '/' . $archivo;
        if (!is_file($ruta)) {
            return '(sin log)';
        }

        return implode(' | ', array_slice(file($ruta, FILE_IGNORE_NEW_LINES), -3));
    }
}
