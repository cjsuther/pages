<?php

/**
 * Productos físicos (#42): lo que se manda por correo o se retira, con su
 * costo de envío, sus características (talle, color...) y su stock.
 *
 * Viven en la misma digital_products que los digitales (tipo = 'fisico'),
 * sin archivo. Lo propio está en tres tablas (migration_productos_fisicos.sql):
 *
 * - product_attributes: las características, de 0 a 3 por producto.
 * - product_attribute_options: las opciones de cada una.
 * - product_variants: el stock de cada combinación de opciones que lo tiene.
 *   Una combinación sin fila, o con stock NULL, no tiene límite.
 *
 * Una opción que ya está en un pedido (product_order_options) no se borra: se
 * desactiva y deja de ofrecerse. Así el pedido sigue diciendo qué se compró.
 *
 * Todavía no se venden: la compra con envío es #43. Acá se valida, se guarda
 * y se muestra.
 */
class ProductosFisicos
{
    const TIPOS = ['digital', 'fisico'];

    const MAX_CARACTERISTICAS = 3;

    const MAX_OPCIONES = 30;

    /** Las filas que tendría la grilla de stock: más que esto no se puede cargar a mano. */
    const MAX_COMBINACIONES = 300;

    /** El largo de las columnas nombre. */
    const MAX_NOMBRE = 50;

    const MAX_ZONA = 255;

    /** El techo de INT. */
    const STOCK_MAXIMO = 2147483647;

    const ERROR_TIPO = 'El tipo de producto tiene que ser digital o físico';

    const ERROR_CAMBIO_DE_TIPO = 'El tipo de un producto no se puede cambiar después de crearlo';

    const ERROR_COSTO_ENVIO = 'El costo de envío tiene que ser un número igual o mayor que 0';

    const ERROR_STOCK = 'El stock tiene que ser un número entero igual o mayor que 0, o quedar vacío';

    // ------------------------------------------------------------ valores

    /**
     * El tipo que se guarda, o null si no es ninguno. Vacío es digital: es
     * lo que eran todos los productos antes de #42.
     *
     * @return string|null
     */
    public static function tipo($valor)
    {
        if ($valor === null || $valor === '') {
            return 'digital';
        }

        return in_array($valor, self::TIPOS, true) ? $valor : null;
    }

    /** true si la fila (de digital_products, o con sus columnas) es de un físico. */
    public static function esFisico($fila)
    {
        return is_array($fila) && isset($fila['tipo']) && $fila['tipo'] === 'fisico';
    }

    /**
     * El stock que se guarda: un entero >= 0, o null (sin límite) si viene
     * vacío. false si no se puede aceptar.
     *
     * @return int|null|false
     */
    public static function stock($valor)
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if (is_int($valor)) {
            $numero = $valor;
        } elseif (is_string($valor) && preg_match('/^\s*\d+\s*$/', $valor) === 1) {
            $numero = (int) trim($valor);
        } elseif (is_float($valor) && floor($valor) === $valor) {
            $numero = (int) $valor;
        } else {
            return false;
        }

        if ($numero < 0 || $numero > self::STOCK_MAXIMO) {
            return false;
        }

        return $numero;
    }

    /**
     * La zona de envío que se guarda: el texto sin espacios de más, o null si
     * queda vacía. false si es más larga que la columna.
     *
     * @return string|null|false
     */
    public static function zonaEnvio($valor)
    {
        if ($valor === null) {
            return null;
        }

        if (!is_string($valor)) {
            return false;
        }

        $zona = trim($valor);

        if ($zona === '') {
            return null;
        }

        return mb_strlen($zona) > self::MAX_ZONA ? false : $zona;
    }

    /** Con qué se comparan dos nombres: sin espacios a los costados y sin mayúsculas. */
    private static function claveDeNombre($nombre)
    {
        return mb_strtolower(trim((string) $nombre));
    }

    /**
     * Las características que llegan del editor, validadas y normalizadas.
     *
     * Cada una es ['id' => ?, 'nombre' => 'Talle', 'opciones' => [...]], y
     * cada opción un texto o ['id' => ?, 'nombre' => 'M']. El id sirve para
     * renombrar sin perder la opción; sin id, se reconoce por el nombre.
     *
     * @return array [lista normalizada, null] o [null, mensaje de error]
     */
    public static function caracteristicas($lista)
    {
        if ($lista === null || $lista === '') {
            return [[], null];
        }

        if (!is_array($lista) || array_values($lista) !== $lista) {
            return [null, 'Las características no tienen el formato esperado'];
        }

        if (count($lista) > self::MAX_CARACTERISTICAS) {
            return [null, 'Un producto puede tener hasta ' . self::MAX_CARACTERISTICAS . ' características'];
        }

        $normalizadas = [];
        $nombres = [];
        $combinaciones = 1;

        foreach ($lista as $c) {
            if (!is_array($c)) {
                return [null, 'Las características no tienen el formato esperado'];
            }

            $nombre = isset($c['nombre']) && is_string($c['nombre']) ? trim($c['nombre']) : '';

            if ($nombre === '') {
                return [null, 'Cada característica necesita un nombre'];
            }

            if (mb_strlen($nombre) > self::MAX_NOMBRE) {
                return [null, 'El nombre "' . $nombre . '" es muy largo: hasta ' . self::MAX_NOMBRE . ' letras'];
            }

            if (isset($nombres[self::claveDeNombre($nombre)])) {
                return [null, 'Hay dos características que se llaman "' . $nombre . '"'];
            }
            $nombres[self::claveDeNombre($nombre)] = true;

            $opciones = isset($c['opciones']) && is_array($c['opciones']) ? array_values($c['opciones']) : [];

            if (empty($opciones)) {
                return [null, '"' . $nombre . '" necesita al menos una opción'];
            }

            if (count($opciones) > self::MAX_OPCIONES) {
                return [null, '"' . $nombre . '" puede tener hasta ' . self::MAX_OPCIONES . ' opciones'];
            }

            $normalizadasOpciones = [];
            $nombresOpciones = [];

            foreach ($opciones as $o) {
                $id = is_array($o) && isset($o['id']) ? (int) $o['id'] : 0;
                $texto = is_array($o) ? (isset($o['nombre']) ? $o['nombre'] : '') : $o;
                $texto = is_string($texto) || is_int($texto) ? trim((string) $texto) : '';

                if ($texto === '') {
                    return [null, 'Hay una opción vacía en "' . $nombre . '"'];
                }

                if (mb_strlen($texto) > self::MAX_NOMBRE) {
                    return [null, 'La opción "' . $texto . '" es muy larga: hasta ' . self::MAX_NOMBRE . ' letras'];
                }

                if (isset($nombresOpciones[self::claveDeNombre($texto)])) {
                    return [null, 'La opción "' . $texto . '" está repetida en "' . $nombre . '"'];
                }
                $nombresOpciones[self::claveDeNombre($texto)] = true;

                $normalizadasOpciones[] = ['id' => $id > 0 ? $id : null, 'nombre' => $texto];
            }

            $combinaciones *= count($normalizadasOpciones);

            $id = isset($c['id']) ? (int) $c['id'] : 0;
            $normalizadas[] = ['id' => $id > 0 ? $id : null, 'nombre' => $nombre, 'opciones' => $normalizadasOpciones];
        }

        if ($combinaciones > self::MAX_COMBINACIONES) {
            return [null, 'Son ' . $combinaciones . ' combinaciones: el máximo es ' . self::MAX_COMBINACIONES . '. Probá con menos opciones.'];
        }

        return [$normalizadas, null];
    }

    /**
     * El stock por combinación que llega del editor, contra las
     * características ya normalizadas.
     *
     * Cada variante es ['opciones' => ['M', 'Rojo'], 'stock' => 5]: un nombre
     * de opción por característica, en el mismo orden. Las que no tienen stock
     * (vacío) no se devuelven: sin fila es sin límite.
     *
     * @return array [[['opciones' => [índices], 'stock' => int], ...], null] o [null, error]
     */
    public static function variantes($lista, array $caracteristicas)
    {
        if ($lista === null || $lista === '') {
            return [[], null];
        }

        if (!is_array($lista)) {
            return [null, 'El stock por combinación no tiene el formato esperado'];
        }

        if (!empty($lista) && empty($caracteristicas)) {
            return [null, 'El stock por combinación necesita características'];
        }

        $resultado = [];
        $vistas = [];

        foreach ($lista as $v) {
            $nombres = is_array($v) && isset($v['opciones']) && is_array($v['opciones']) ? array_values($v['opciones']) : null;

            if ($nombres === null || count($nombres) !== count($caracteristicas)) {
                return [null, 'Cada combinación tiene que tener una opción de cada característica'];
            }

            $indices = [];

            foreach ($caracteristicas as $i => $c) {
                $buscada = self::claveDeNombre(is_scalar($nombres[$i]) ? $nombres[$i] : '');
                $encontrada = null;

                foreach ($c['opciones'] as $j => $o) {
                    if (self::claveDeNombre($o['nombre']) === $buscada) {
                        $encontrada = $j;
                        break;
                    }
                }

                if ($encontrada === null) {
                    return [null, 'La combinación ' . implode(' / ', array_map('strval', array_filter($nombres, 'is_scalar'))) . ' no existe'];
                }

                $indices[] = $encontrada;
            }

            $clave = implode('-', $indices);

            if (isset($vistas[$clave])) {
                return [null, 'Hay una combinación repetida en el stock'];
            }
            $vistas[$clave] = true;

            $stock = self::stock(isset($v['stock']) ? $v['stock'] : null);

            if ($stock === false) {
                return [null, self::ERROR_STOCK];
            }

            if ($stock !== null) {
                $resultado[] = ['opciones' => $indices, 'stock' => $stock];
            }
        }

        return [$resultado, null];
    }

    /**
     * Lo propio de un físico que viene en un pedido de alta o edición, ya
     * validado. Sólo las claves que llegaron: en una edición, lo que no vino
     * no se toca. Si vienen características sin variantes, el stock por
     * combinación queda vacío (sin límite).
     *
     * @return array [datos, null] o [null, mensaje de error]
     */
    public static function datosDelPedido(array $cuerpo)
    {
        $datos = [];

        if (array_key_exists('costo_envio', $cuerpo)) {
            $costo = ProductosDigitales::precio($cuerpo['costo_envio']);

            if ($costo === null) {
                return [null, self::ERROR_COSTO_ENVIO];
            }

            $datos['costo_envio'] = $costo;
        }

        if (array_key_exists('zona_envio', $cuerpo)) {
            $zona = self::zonaEnvio($cuerpo['zona_envio']);

            if ($zona === false) {
                return [null, 'La zona de envío puede tener hasta ' . self::MAX_ZONA . ' letras'];
            }

            $datos['zona_envio'] = $zona;
        }

        if (array_key_exists('stock', $cuerpo)) {
            $stock = self::stock($cuerpo['stock']);

            if ($stock === false) {
                return [null, self::ERROR_STOCK];
            }

            $datos['stock'] = $stock;
        }

        if (array_key_exists('caracteristicas', $cuerpo)) {
            list($caracteristicas, $error) = self::caracteristicas($cuerpo['caracteristicas']);

            if ($error !== null) {
                return [null, $error];
            }

            list($variantes, $error) = self::variantes(
                isset($cuerpo['variantes']) ? $cuerpo['variantes'] : null,
                $caracteristicas
            );

            if ($error !== null) {
                return [null, $error];
            }

            $datos['caracteristicas'] = $caracteristicas;
            $datos['variantes'] = $variantes;

            // Con características el stock va por combinación: el general no
            // significa nada y se vacía, para que nadie lo lea por error.
            if (!empty($caracteristicas)) {
                $datos['stock'] = null;
            }
        } elseif (array_key_exists('variantes', $cuerpo)) {
            return [null, 'El stock por combinación se guarda junto con las características'];
        }

        return [$datos, null];
    }

    // --------------------------------------------------------------- base

    /**
     * Guarda lo propio de un físico. Tiene que correr dentro de una
     * transacción: las características se tocan en varias consultas.
     */
    public static function guardar($db, $linkId, array $datos)
    {
        $asignaciones = [];
        $valores = [];

        foreach (['costo_envio', 'zona_envio', 'stock'] as $campo) {
            if (array_key_exists($campo, $datos)) {
                $asignaciones[] = $campo . ' = ?';
                $valores[] = $datos[$campo];
            }
        }

        if (!empty($asignaciones)) {
            $valores[] = (int) $linkId;
            $stmt = $db->prepare('UPDATE digital_products SET ' . implode(', ', $asignaciones) . ' WHERE link_id = ?');
            $stmt->execute($valores);
        }

        if (array_key_exists('caracteristicas', $datos)) {
            $producto = ProductosDigitales::delLink($db, $linkId);

            if ($producto === null) {
                throw new RuntimeException('El producto no existe');
            }

            $ids = self::guardarCaracteristicas($db, (int) $producto['id'], $datos['caracteristicas']);
            self::guardarVariantes($db, (int) $producto['id'], $ids, $datos['variantes']);
        }
    }

    /** Marca el producto recién creado como físico. */
    public static function marcarFisico($db, $linkId)
    {
        $stmt = $db->prepare('UPDATE digital_products SET tipo = ? WHERE link_id = ?');
        $stmt->execute(['fisico', (int) $linkId]);
    }

    /**
     * Deja las características del producto como dice la lista.
     *
     * Las que llegan se reconocen por id o, si no, por nombre (así volver a
     * agregar una opción desactivada la reactiva). Lo que no llega se borra,
     * salvo una opción que ya está en un pedido: esa se desactiva, y su
     * característica también si se quitó entera.
     *
     * @return array por característica, los ids de sus opciones en orden
     */
    private static function guardarCaracteristicas($db, $productoId, array $caracteristicas)
    {
        $stmt = $db->prepare('SELECT id, nombre FROM product_attributes WHERE product_id = ? ORDER BY posicion, id');
        $stmt->execute([$productoId]);
        $atributos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $db->prepare('
            SELECT o.id, o.attribute_id, o.nombre,
                   EXISTS (SELECT 1 FROM product_order_options poo WHERE poo.option_id = o.id) AS usada
            FROM product_attribute_options o
            INNER JOIN product_attributes a ON a.id = o.attribute_id
            WHERE a.product_id = ?
            ORDER BY o.posicion, o.id
        ');
        $stmt->execute([$productoId]);
        $opciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $opcionesDe = [];
        foreach ($opciones as $o) {
            $opcionesDe[(int) $o['attribute_id']][] = $o;
        }

        $atributosQueQuedan = [];
        $opcionesQueQuedan = [];
        $ids = [];

        foreach ($caracteristicas as $posicion => $c) {
            $existente = self::reconocer($atributos, $c, $atributosQueQuedan);

            if ($existente === null) {
                $ins = $db->prepare('INSERT INTO product_attributes (product_id, nombre, posicion, activa) VALUES (?, ?, ?, 1)');
                $ins->execute([$productoId, $c['nombre'], $posicion]);
                $atributoId = (int) $db->lastInsertId();
            } else {
                $atributoId = (int) $existente['id'];
                $upd = $db->prepare('UPDATE product_attributes SET nombre = ?, posicion = ?, activa = 1 WHERE id = ?');
                $upd->execute([$c['nombre'], $posicion, $atributoId]);
            }

            $atributosQueQuedan[$atributoId] = true;
            $propias = isset($opcionesDe[$atributoId]) ? $opcionesDe[$atributoId] : [];
            $ids[$posicion] = [];

            foreach ($c['opciones'] as $orden => $o) {
                $existenteOpcion = self::reconocer($propias, $o, $opcionesQueQuedan);

                if ($existenteOpcion === null) {
                    $ins = $db->prepare('INSERT INTO product_attribute_options (attribute_id, nombre, posicion, activa) VALUES (?, ?, ?, 1)');
                    $ins->execute([$atributoId, $o['nombre'], $orden]);
                    $opcionId = (int) $db->lastInsertId();
                } else {
                    $opcionId = (int) $existenteOpcion['id'];
                    $upd = $db->prepare('UPDATE product_attribute_options SET nombre = ?, posicion = ?, activa = 1 WHERE id = ?');
                    $upd->execute([$o['nombre'], $orden, $opcionId]);
                }

                $opcionesQueQuedan[$opcionId] = true;
                $ids[$posicion][] = $opcionId;
            }
        }

        // Lo que no llegó: primero las opciones, después las características.
        $conUsadas = [];

        foreach ($opciones as $o) {
            $id = (int) $o['id'];

            if (isset($opcionesQueQuedan[$id])) {
                continue;
            }

            if ((int) $o['usada']) {
                $upd = $db->prepare('UPDATE product_attribute_options SET activa = 0 WHERE id = ?');
                $upd->execute([$id]);
                $conUsadas[(int) $o['attribute_id']] = true;
            } else {
                $del = $db->prepare('DELETE FROM product_attribute_options WHERE id = ?');
                $del->execute([$id]);
            }
        }

        foreach ($atributos as $a) {
            $id = (int) $a['id'];

            if (isset($atributosQueQuedan[$id])) {
                continue;
            }

            if (isset($conUsadas[$id])) {
                $upd = $db->prepare('UPDATE product_attributes SET activa = 0 WHERE id = ?');
                $upd->execute([$id]);
            } else {
                $del = $db->prepare('DELETE FROM product_attributes WHERE id = ?');
                $del->execute([$id]);
            }
        }

        return $ids;
    }

    /**
     * Cuál de las filas existentes es la que llega: la de su id si es de
     * este producto, si no la del mismo nombre. Nunca una ya tomada.
     */
    private static function reconocer(array $existentes, array $llega, array $tomadas)
    {
        if (!empty($llega['id'])) {
            foreach ($existentes as $e) {
                if ((int) $e['id'] === (int) $llega['id'] && !isset($tomadas[(int) $e['id']])) {
                    return $e;
                }
            }
        }

        foreach ($existentes as $e) {
            if (self::claveDeNombre($e['nombre']) === self::claveDeNombre($llega['nombre']) && !isset($tomadas[(int) $e['id']])) {
                return $e;
            }
        }

        return null;
    }

    /** Reemplaza el stock por combinación. */
    private static function guardarVariantes($db, $productoId, array $ids, array $variantes)
    {
        $del = $db->prepare('DELETE FROM product_variants WHERE product_id = ?');
        $del->execute([$productoId]);

        foreach ($variantes as $v) {
            $opciones = [];

            foreach ($v['opciones'] as $i => $j) {
                $opciones[] = $ids[$i][$j];
            }

            $ins = $db->prepare('INSERT INTO product_variants (product_id, clave, stock) VALUES (?, ?, ?)');
            $ins->execute([$productoId, self::clave($opciones), $v['stock']]);
        }
    }

    /** La clave de una combinación: los ids de sus opciones, de menor a mayor. */
    public static function clave(array $opcionIds)
    {
        $ids = array_map('intval', $opcionIds);
        sort($ids);

        return implode('-', $ids);
    }

    // ------------------------------------------------------------ lectura

    /**
     * Les suma a las filas de productos físicos sus características (sólo
     * las activas, con sus opciones activas), el stock por combinación y si
     * están agotados. Las filas tienen que traer producto_id y tipo.
     */
    public static function completar($db, array $filas)
    {
        $productoIds = [];

        foreach ($filas as $fila) {
            if (self::esFisico($fila) && !empty($fila['producto_id'])) {
                $productoIds[] = (int) $fila['producto_id'];
            }
        }

        if (empty($productoIds)) {
            return $filas;
        }

        $marcas = implode(', ', array_fill(0, count($productoIds), '?'));

        $stmt = $db->prepare("
            SELECT a.id AS atributo_id, a.product_id, a.nombre AS atributo, o.id AS opcion_id, o.nombre AS opcion
            FROM product_attributes a
            INNER JOIN product_attribute_options o ON o.attribute_id = a.id AND o.activa = 1
            WHERE a.product_id IN ($marcas) AND a.activa = 1
            ORDER BY a.product_id, a.posicion, a.id, o.posicion, o.id
        ");
        $stmt->execute($productoIds);

        $caracteristicas = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $p = (int) $f['product_id'];
            $a = (int) $f['atributo_id'];

            if (!isset($caracteristicas[$p][$a])) {
                $caracteristicas[$p][$a] = ['id' => $a, 'nombre' => $f['atributo'], 'opciones' => []];
            }

            $caracteristicas[$p][$a]['opciones'][] = ['id' => (int) $f['opcion_id'], 'nombre' => $f['opcion']];
        }

        $stmt = $db->prepare("SELECT product_id, clave, stock FROM product_variants WHERE product_id IN ($marcas) AND stock IS NOT NULL ORDER BY id");
        $stmt->execute($productoIds);

        $variantes = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $variantes[(int) $f['product_id']][$f['clave']] = (int) $f['stock'];
        }

        foreach ($filas as &$fila) {
            if (!self::esFisico($fila) || empty($fila['producto_id'])) {
                continue;
            }

            $p = (int) $fila['producto_id'];
            $suyas = isset($caracteristicas[$p]) ? array_values($caracteristicas[$p]) : [];
            $stock = isset($variantes[$p]) ? $variantes[$p] : [];

            // Sólo las combinaciones que existen hoy: las de una opción que se
            // quitó no se ofrecen más.
            $vigentes = [];
            foreach (self::combinaciones($suyas) as $clave) {
                if (array_key_exists($clave, $stock)) {
                    $vigentes[] = ['clave' => $clave, 'stock' => $stock[$clave]];
                }
            }

            $fila['caracteristicas'] = $suyas;
            $fila['variantes'] = $vigentes;
            $fila['stock'] = empty($suyas) && isset($fila['stock']) && $fila['stock'] !== null ? (int) $fila['stock'] : null;
            $fila['agotado'] = self::agotado($fila);
        }
        unset($fila);

        return $filas;
    }

    /**
     * Las claves de todas las combinaciones de estas características. Sin
     * características, ninguna.
     *
     * @return string[]
     */
    public static function combinaciones(array $caracteristicas)
    {
        if (empty($caracteristicas)) {
            return [];
        }

        $parciales = [[]];

        foreach ($caracteristicas as $c) {
            $siguientes = [];

            foreach ($parciales as $parcial) {
                foreach ($c['opciones'] as $o) {
                    $siguientes[] = array_merge($parcial, [(int) $o['id']]);
                }
            }

            $parciales = $siguientes;
        }

        return array_map([self::class, 'clave'], $parciales);
    }

    /**
     * Si no queda nada para elegir: sin características, stock 0; con
     * características, todas las combinaciones con stock 0. Una combinación
     * sin stock cargado no tiene límite, así que nunca se agota.
     */
    public static function agotado(array $producto)
    {
        if (!self::esFisico($producto)) {
            return false;
        }

        $caracteristicas = isset($producto['caracteristicas']) ? $producto['caracteristicas'] : [];

        if (empty($caracteristicas)) {
            return isset($producto['stock']) && $producto['stock'] !== null && (int) $producto['stock'] <= 0;
        }

        $stock = [];
        foreach (isset($producto['variantes']) ? $producto['variantes'] : [] as $v) {
            $stock[$v['clave']] = $v['stock'];
        }

        foreach (self::combinaciones($caracteristicas) as $clave) {
            if (!array_key_exists($clave, $stock) || $stock[$clave] === null || (int) $stock[$clave] > 0) {
                return false;
            }
        }

        return true;
    }
}
