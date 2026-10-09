<?php

/**
 * Los tipos de entrada de un evento: varios precios, cada uno con su nombre.
 *
 *     [{"id": "general", "nombre": "General", "precio": 8000, "cupo": null},
 *      {"id": "jubilados", "nombre": "Jubilados", "precio": 5000, "cupo": 20}]
 *
 * El id lo pone el editor y no cambia aunque el tipo se renombre: es lo que
 * nombra el plano en cada fila o mesa, y lo que guarda cada compra para que el
 * cupo del tipo se pueda contar.
 *
 * Sin tipos —null— el evento tiene un solo precio, como siempre.
 */
class TiposDeEntrada
{
    const MAX_TIPOS = 20;
    const MAX_NOMBRE = 60;

    /** Lo mismo que acepta la columna de la compra. */
    const PATRON_ID = '/^[A-Za-z0-9_-]{1,20}$/';

    /**
     * Valida los tipos y los devuelve limpios. Una lista vacía es null: un
     * evento sin tipos vende a un solo precio.
     *
     * @param mixed $tipos Lo que mandó el editor, ya decodificado.
     * @return array{ok: bool, error: string|null, tipos: array|null}
     */
    public static function normalizar($tipos)
    {
        if ($tipos === null || $tipos === []) {
            return ['ok' => true, 'error' => null, 'tipos' => null];
        }

        if (!is_array($tipos)) {
            return self::mal('Los tipos de entrada no tienen el formato esperado');
        }

        if (count($tipos) > self::MAX_TIPOS) {
            return self::mal('No puede haber más de ' . self::MAX_TIPOS . ' tipos de entrada');
        }

        $limpios = [];
        $ids = [];
        $nombres = [];

        foreach (array_values($tipos) as $crudo) {
            if (!is_array($crudo)) {
                return self::mal('Los tipos de entrada no tienen el formato esperado');
            }

            $id = isset($crudo['id']) ? (string) $crudo['id'] : '';
            $nombre = isset($crudo['nombre']) ? trim((string) $crudo['nombre']) : '';
            $precio = isset($crudo['precio']) && $crudo['precio'] !== '' ? round((float) $crudo['precio'], 2) : 0.0;
            $cupo = isset($crudo['cupo']) && $crudo['cupo'] !== '' && $crudo['cupo'] !== null ? (int) $crudo['cupo'] : null;

            if (!preg_match(self::PATRON_ID, $id)) {
                return self::mal('Un tipo de entrada no tiene un identificador válido');
            }

            if ($nombre === '') {
                return self::mal('Cada tipo de entrada necesita un nombre');
            }

            if (mb_strlen($nombre) > self::MAX_NOMBRE) {
                return self::mal('El nombre de un tipo de entrada no puede pasar de ' . self::MAX_NOMBRE . ' letras');
            }

            if ($precio < 0) {
                return self::mal("El precio de \"$nombre\" no puede ser negativo");
            }

            if ($cupo !== null && $cupo < 1) {
                return self::mal("El cupo de \"$nombre\" tiene que ser al menos 1, o quedar vacío");
            }

            // Dos "General" no se distinguen en el mail ni en las ventas.
            $clave = mb_strtolower($nombre);

            if (isset($ids[$id]) || isset($nombres[$clave])) {
                return self::mal("Hay dos tipos de entrada que se llaman \"$nombre\"");
            }

            $ids[$id] = true;
            $nombres[$clave] = true;
            $limpios[] = ['id' => $id, 'nombre' => $nombre, 'precio' => $precio, 'cupo' => $cupo];
        }

        return ['ok' => true, 'error' => null, 'tipos' => $limpios];
    }

    /** Los tipos guardados en la configuración, decodificados, o null. */
    public static function decodificar($json)
    {
        if ($json === null || $json === '') {
            return null;
        }

        $tipos = is_array($json) ? $json : json_decode((string) $json, true);

        return is_array($tipos) && $tipos !== [] ? $tipos : null;
    }

    /**
     * El precio que se guarda como "el" precio del evento.
     *
     * Es el más barato de los que cobran, no el más barato a secas: el resto
     * del sistema pregunta "precio > 0" para saber si el evento cobra —si
     * necesita Mercado Pago, si se anuncia como gratis— y un tipo "Invitado"
     * en 0 no puede convertir en gratis a un evento que vende entradas.
     */
    public static function precioDeReferencia(array $tipos)
    {
        $pagos = array_filter(array_map(function ($t) { return (float) $t['precio']; }, $tipos), function ($p) {
            return $p > 0;
        });

        return $pagos === [] ? 0.0 : min($pagos);
    }

    /** @return array<string, array> Los tipos por id. */
    public static function porId(array $tipos)
    {
        $porId = [];

        foreach ($tipos as $tipo) {
            $porId[(string) $tipo['id']] = $tipo;
        }

        return $porId;
    }

    /**
     * El tipo de cada lugar del plano.
     *
     * Lo dice la fila o la mesa. Una que no dice —un plano armado antes de los
     * tipos, o copiado de otro evento— o que nombra un tipo que ya no existe
     * vende al primero: ningún lugar del plano puede quedar sin precio.
     *
     * @return array<string, string> id de lugar => id de tipo
     */
    public static function tiposPorLugar(array $plano, array $tipos)
    {
        $porId = self::porId($tipos);
        $primero = (string) $tipos[0]['id'];
        $resultado = [];

        foreach ($plano['elementos'] as $elemento) {
            if ($elemento['tipo'] !== 'fila' && $elemento['tipo'] !== 'mesa') {
                continue;
            }

            $tipo = isset($elemento['entrada']) && isset($porId[$elemento['entrada']])
                ? (string) $elemento['entrada']
                : $primero;

            foreach (Plano::lugares(['elementos' => [$elemento]]) as $lugar) {
                $resultado[$lugar] = $tipo;
            }
        }

        return $resultado;
    }

    /** "2 General · 1 Jubilados", para el mail y las listas. */
    public static function resumir(array $items)
    {
        return implode(' · ', array_map(function ($item) {
            return (int) $item['cantidad'] . ' ' . $item['nombre'];
        }, $items));
    }

    private static function mal($error)
    {
        return ['ok' => false, 'error' => $error, 'tipos' => null];
    }
}
