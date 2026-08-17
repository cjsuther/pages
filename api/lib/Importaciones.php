<?php

/**
 * Coordina la corrida diaria: qué fuentes hay y quién sabe leer cada una.
 *
 * El registro de adaptadores vive acá y no dentro del cron para que sumar una
 * fuente sea agregar un archivo y una línea, sin tocar el proceso.
 */
class Importaciones
{
    /**
     * Adaptador por nombre. La clave es lo que guarda import_sources.
     *
     * Reciben la conexión porque algunos necesitan geocodificar, y el
     * geocodificador cachea en la base para no volver a preguntar por la misma
     * sala en cada corrida.
     */
    public static function adaptadores($db)
    {
        return [
            'eventbrite' => function (array $parametros) {
                return (new Eventbrite())->eventos($parametros);
            },
            'boleteria' => function (array $parametros) use ($db) {
                return (new Boleteria())->eventos($parametros, $db);
            },
            'niceto' => function (array $parametros) use ($db) {
                return (new Niceto())->eventos($parametros, $db);
            },
            'colon' => function (array $parametros) use ($db) {
                return (new Colon())->eventos($parametros, $db);
            },
            'artmedia' => function (array $parametros) use ($db) {
                return (new ArtMedia())->eventos($parametros, $db);
            },
        ];
    }

    /** Fuentes activas, empezando por las que hace más que no corren. */
    public static function activas($db, $limite = 20)
    {
        $stmt = $db->prepare('
            SELECT * FROM import_sources
            WHERE activo = 1
            ORDER BY ultima_corrida IS NOT NULL, ultima_corrida
            LIMIT ' . (int) $limite);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Corre todas las fuentes activas.
     *
     * Una fuente que falla no puede frenar a las demás: son sitios ajenos y
     * que uno se caiga es lo esperable, no lo excepcional.
     */
    public static function correr($db, array $adaptadores = null)
    {
        $adaptadores = $adaptadores === null ? self::adaptadores($db) : $adaptadores;
        $resumen = ['fuentes' => 0, 'creados' => 0, 'actualizados' => 0, 'fallidas' => 0, 'detalle' => []];

        foreach (self::activas($db) as $fuente) {
            $resumen['fuentes']++;

            if (!isset($adaptadores[$fuente['adaptador']])) {
                $resumen['fallidas']++;
                $resumen['detalle'][] = "{$fuente['nombre']}: no hay adaptador '{$fuente['adaptador']}'";
                continue;
            }

            try {
                $r = Importador::sincronizar($db, $fuente, $adaptadores[$fuente['adaptador']]);
            } catch (Throwable $e) {
                $resumen['fallidas']++;
                $resumen['detalle'][] = "{$fuente['nombre']}: " . substr($e->getMessage(), 0, 120);
                continue;
            }

            if (!$r['ok']) {
                $resumen['fallidas']++;
                $resumen['detalle'][] = "{$fuente['nombre']}: {$r['error']}";
                continue;
            }

            $resumen['creados'] += $r['creados'];
            $resumen['actualizados'] += $r['actualizados'];
            $resumen['detalle'][] = "{$fuente['nombre']}: {$r['creados']} nuevos, {$r['actualizados']} actualizados";
        }

        return $resumen;
    }
}
