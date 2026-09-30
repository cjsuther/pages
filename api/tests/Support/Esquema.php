<?php

namespace Tests\Support;

use PDO;
use RuntimeException;

/**
 * El esquema de la base según el repositorio: database.sql y las migraciones,
 * aplicadas en el orden de migraciones.txt.
 *
 * Sirve para dos cosas. Los tests de integración arman con esto la base contra
 * la que corren. Y deploy.sh compara el resultado —guardado en
 * tests/esquema-esperado.json— con la base de producción, para no subir código
 * que usa una tabla o una columna que allá todavía no existe.
 */
class Esquema
{
    /**
     * Errores que significan "esto ya estaba": database.sql se fue actualizando
     * en el lugar y ya trae columnas que agregan migraciones posteriores, así
     * que aplicar la cadena entera choca con lo que ya existe. Cualquier otro
     * error es un problema de verdad y corta.
     *
     * 1050 tabla existe · 1060 columna duplicada · 1061 índice duplicado ·
     * 1091 no se puede borrar algo que no existe · 1826 FK duplicada
     */
    const YA_APLICADO = [1050, 1060, 1061, 1091, 1826];

    /** Raíz del repositorio, donde viven database.sql y las migraciones. */
    public static function raiz()
    {
        return realpath(__DIR__ . '/../../..');
    }

    /** Archivos en el orden en que se aplican, según migraciones.txt. */
    public static function orden()
    {
        $ruta = self::raiz() . '/migraciones.txt';
        if (!is_file($ruta)) {
            throw new RuntimeException("falta $ruta");
        }

        $archivos = [];
        foreach (file($ruta, FILE_IGNORE_NEW_LINES) as $linea) {
            $linea = trim($linea);
            if ($linea !== '' && $linea[0] !== '#') {
                $archivos[] = $linea;
            }
        }

        return $archivos;
    }

    /** Los migration_*.sql que hay en el repositorio, estén listados o no. */
    public static function migracionesEnDisco()
    {
        return array_map('basename', glob(self::raiz() . '/migration_*.sql'));
    }

    /**
     * Aplica todo sobre una base vacía. Devuelve, por archivo, las tablas y
     * columnas que agregó: es lo que permite decir qué migración falta cuando
     * a producción le falta algo.
     */
    public static function aplicar(PDO $db)
    {
        $origen = [];
        $antes = self::describir($db);

        foreach (self::orden() as $archivo) {
            foreach (self::sentencias(file_get_contents(self::raiz() . '/' . $archivo)) as $sql) {
                try {
                    $db->exec($sql);
                } catch (\PDOException $e) {
                    if (!in_array((int) ($e->errorInfo[1] ?? 0), self::YA_APLICADO, true)) {
                        throw new RuntimeException("$archivo: " . $e->getMessage() . "\n  en: " . substr($sql, 0, 200));
                    }
                }
            }

            $despues = self::describir($db);
            foreach ($despues as $tabla => $columnas) {
                foreach ($columnas as $columna) {
                    if (!isset($antes[$tabla]) || !in_array($columna, $antes[$tabla], true)) {
                        $origen[$tabla][$columna] = $archivo;
                    }
                }
            }
            $antes = $despues;
        }

        // Lo que una migración posterior borró ya no forma parte del esquema.
        $final = self::describir($db);
        $esperado = [];
        foreach ($final as $tabla => $columnas) {
            foreach ($columnas as $columna) {
                $esperado[$tabla][$columna] = $origen[$tabla][$columna] ?? 'database.sql';
            }
        }
        ksort($esperado);

        return $esperado;
    }

    /** Tablas y columnas de la base a la que apunta la conexión. */
    public static function describir(PDO $db)
    {
        $filas = $db->query('
            SELECT TABLE_NAME AS tabla, COLUMN_NAME AS columna
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
            ORDER BY TABLE_NAME, ORDINAL_POSITION
        ')->fetchAll(PDO::FETCH_ASSOC);

        $tablas = [];
        foreach ($filas as $fila) {
            $tablas[$fila['tabla']][] = $fila['columna'];
        }

        return $tablas;
    }

    /**
     * Lo que el esquema esperado tiene y la base real no, agrupado por la
     * migración que lo crea. Lo que la base tiene de más no importa: el código
     * no lo usa.
     *
     * @param array $esperado tabla => [columna => migración]
     * @param array $real     tabla => [columna, ...]
     * @return array migración => [descripciones]
     */
    public static function faltantes(array $esperado, array $real)
    {
        $faltan = [];

        foreach ($esperado as $tabla => $columnas) {
            if (!isset($real[$tabla])) {
                $migraciones = array_unique(array_values($columnas));
                foreach ($migraciones as $migracion) {
                    $faltan[$migracion][] = "tabla $tabla";
                }
                continue;
            }

            foreach ($columnas as $columna => $migracion) {
                if (!in_array($columna, $real[$tabla], true)) {
                    $faltan[$migracion][] = "columna $tabla.$columna";
                }
            }
        }

        ksort($faltan);

        return $faltan;
    }

    /**
     * Parte un archivo SQL en sentencias. Saca los comentarios y el
     * CREATE DATABASE / USE de database.sql, que apuntaría a otra base.
     */
    public static function sentencias($sql)
    {
        $sentencias = [];
        $actual = '';
        $largo = strlen($sql);
        $comilla = null;

        for ($i = 0; $i < $largo; $i++) {
            $c = $sql[$i];

            if ($comilla !== null) {
                $actual .= $c;
                if ($c === '\\' && $i + 1 < $largo) {
                    $actual .= $sql[++$i];
                } elseif ($c === $comilla) {
                    $comilla = null;
                }
                continue;
            }

            if ($c === "'" || $c === '"' || $c === '`') {
                $comilla = $c;
                $actual .= $c;
                continue;
            }

            // Comentario de línea: "-- " o "#".
            if (($c === '-' && substr($sql, $i, 3) === '-- ') || ($c === '-' && substr($sql, $i, 3) === "--\n") || $c === '#') {
                $fin = strpos($sql, "\n", $i);
                $i = $fin === false ? $largo : $fin;
                $actual .= "\n";
                continue;
            }

            if ($c === '/' && substr($sql, $i, 2) === '/*') {
                $fin = strpos($sql, '*/', $i + 2);
                $i = $fin === false ? $largo : $fin + 1;
                continue;
            }

            if ($c === ';') {
                $sentencias[] = trim($actual);
                $actual = '';
                continue;
            }

            $actual .= $c;
        }

        $sentencias[] = trim($actual);

        return array_values(array_filter($sentencias, function ($s) {
            return $s !== '' && !preg_match('/^(CREATE\s+DATABASE|USE)\s/i', $s);
        }));
    }
}
