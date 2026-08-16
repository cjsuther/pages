<?php

/**
 * Redes sociales de una página.
 *
 * El catálogo vive también en frontend/src/utils/redes.js, que es el que arma
 * el formulario. Acá se repite sólo la lista de claves permitidas, para no
 * guardar cualquier cosa que llegue por la API.
 */
class Redes
{
    /** Claves aceptadas. Debe coincidir con REDES en frontend/src/utils/redes.js. */
    private static $permitidas = [
        'instagram', 'tiktok', 'youtube', 'facebook', 'whatsapp',
        'x', 'spotify', 'linkedin', 'telegram', 'cafecito', 'email', 'web',
    ];

    const MAX_LONGITUD_URL = 500;

    public static function permitida($clave)
    {
        return in_array($clave, self::$permitidas, true);
    }

    public static function catalogo()
    {
        return self::$permitidas;
    }

    /** Redes cargadas de una página, en el orden configurado. */
    public static function deLaPagina($db, $pageId)
    {
        $stmt = $db->prepare('
            SELECT red, url, position
            FROM page_socials
            WHERE page_id = ?
            ORDER BY position, id
        ');
        $stmt->execute([$pageId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Reemplaza el conjunto de redes de una página.
     *
     * Se recibe la lista completa y se sincroniza: lo que no viene, se borra.
     * Es lo que espera el editor, que manda el estado final del formulario.
     *
     * Las entradas sin URL se descartan en lugar de guardarse vacías: la razón
     * de ser de esta sección es que en la página pública sólo aparezcan las
     * redes que el usuario realmente completó.
     *
     * @param array $redes Lista de ['red' => clave, 'url' => string]
     * @return array{guardadas: int, ignoradas: string[]}
     */
    public static function reemplazar($db, $pageId, array $redes)
    {
        $aGuardar = [];
        $ignoradas = [];
        $posicion = 0;

        foreach ($redes as $entrada) {
            $clave = isset($entrada['red']) ? trim((string) $entrada['red']) : '';
            $url = isset($entrada['url']) ? trim((string) $entrada['url']) : '';

            if ($url === '') {
                continue; // Campo vacío: simplemente no se guarda.
            }

            if (!self::permitida($clave)) {
                $ignoradas[] = $clave;
                continue;
            }

            // Una sola cuenta por red: si llegan dos, gana la primera.
            if (isset($aGuardar[$clave])) {
                continue;
            }

            $aGuardar[$clave] = [
                'red' => $clave,
                'url' => substr($url, 0, self::MAX_LONGITUD_URL),
                'position' => $posicion++,
            ];
        }

        $db->beginTransaction();

        try {
            $db->prepare('DELETE FROM page_socials WHERE page_id = ?')->execute([$pageId]);

            if (!empty($aGuardar)) {
                $insert = $db->prepare('
                    INSERT INTO page_socials (page_id, red, url, position)
                    VALUES (?, ?, ?, ?)
                ');

                foreach ($aGuardar as $fila) {
                    $insert->execute([$pageId, $fila['red'], $fila['url'], $fila['position']]);
                }
            }

            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }

        return ['guardadas' => count($aGuardar), 'ignoradas' => $ignoradas];
    }
}
