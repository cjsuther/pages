<?php

namespace Tests\Support;

use PDO;
use PHPUnit\Framework\TestCase;
use Plataforma;
use Request;
use Response;

/**
 * Base de los tests que corren contra una MariaDB de verdad.
 *
 * El esquema se arma una sola vez por corrida, desde database.sql y las
 * migraciones —lo mismo que tiene producción—, y entre test y test se vacían
 * las tablas. Así cada consulta del código pasa por el motor real: una tabla o
 * una columna que ninguna migración crea hace fallar el test.
 *
 * Sin MariaDB los tests se saltean, salvo con RZ_EXIGIR_BASE=1 (lo pone
 * deploy.sh): ahí la falta de base es un error, para que el deploy no pase
 * creyendo que se probó algo que no se probó.
 */
abstract class IntegracionTestCase extends TestCase
{
    const BASE = 'rz_integracion';

    /** @var PDO */
    protected $db;

    private static $esquema = null;
    private static $tablas = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (!BaseDescartable::disponible()) {
            $mensaje = 'Sin MariaDB para los tests de integración: ' . BaseDescartable::motivo();
            if (getenv('RZ_EXIGIR_BASE')) {
                $this->fail($mensaje);
            }
            $this->markTestSkipped($mensaje);
        }

        if (self::$esquema === null) {
            $db = BaseDescartable::baseNueva(self::BASE);
            self::$esquema = Esquema::aplicar($db);
            self::$tablas = array_keys(self::$esquema);
        }

        $this->db = BaseDescartable::conectar(self::BASE);
        $this->vaciar();

        Plataforma::olvidar();
    }

    /**
     * PHPUnit guarda cada test hasta el final de la corrida, y con él su
     * conexión: pasados los 151 tests de integración, el servidor rechazaba
     * las siguientes con "Too many connections".
     */
    protected function tearDown(): void
    {
        $this->db = null;

        parent::tearDown();
    }

    /** El esquema que armaron database.sql y las migraciones: tabla => [columna => migración]. */
    protected static function esquema()
    {
        return self::$esquema;
    }

    private function vaciar()
    {
        // Reiniciar el contador es un ALTER por tabla, y cuesta: sobre las 31
        // tablas eran 2,5 segundos por test. Sólo se reinicia en las que el
        // test anterior usó, que son unas pocas.
        $usadas = $this->db->query('
            SELECT TABLE_NAME FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND AUTO_INCREMENT > 1
        ')->fetchAll(PDO::FETCH_COLUMN);

        $this->db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::$tablas as $tabla) {
            $this->db->exec("DELETE FROM `$tabla`");
        }
        foreach ($usadas as $tabla) {
            $this->db->exec("ALTER TABLE `$tabla` AUTO_INCREMENT = 1");
        }
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    // ------------------------------------------------------------- datos

    /** Inserta una fila y devuelve su id. */
    protected function insertar($tabla, array $fila)
    {
        $columnas = array_keys($fila);
        $sql = "INSERT INTO `$tabla` (`" . implode('`, `', $columnas) . '`) VALUES ('
            . implode(', ', array_fill(0, count($columnas), '?')) . ')';
        $this->db->prepare($sql)->execute(array_values($fila));

        return (int) $this->db->lastInsertId();
    }

    protected function usuario($email = 'duena@test.local', $nombre = 'Dueña')
    {
        return $this->insertar('users', [
            'email' => $email,
            'password' => password_hash('x', PASSWORD_DEFAULT),
            'name' => $nombre,
        ]);
    }

    protected function pagina($userId, $slug = 'la-sala', $titulo = 'La Sala')
    {
        return $this->insertar('pages', [
            'user_id' => $userId,
            'title' => $titulo,
            'url_slug' => $slug,
        ]);
    }

    protected function grupo($pageId, $tipo = 'eventos', $titulo = 'Fechas')
    {
        return $this->insertar('link_groups', [
            'page_id' => $pageId,
            'title' => $titulo,
            'type' => $tipo,
            'position' => 0,
        ]);
    }

    protected function evento($groupId, array $datos = [])
    {
        return $this->insertar('links', array_merge([
            'group_id' => $groupId,
            'text' => 'Show de prueba',
            'url' => '',
            'position' => 0,
            'event_date' => date('Y-m-d', strtotime('+10 days')),
            'event_time' => '21:00:00',
            'event_address' => 'Av. Corrientes 1234, CABA',
        ], $datos));
    }

    protected function fila($sql, array $params = [])
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    protected function valor($sql, array $params = [])
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn();
    }

    // ------------------------------------------------------------ peticiones

    protected function user($id, $email = 'duena@test.local')
    {
        return ['user_id' => $id, 'email' => $email];
    }

    protected function get(array $query = [], $user = null)
    {
        return new Request('GET', [], $query, $user, [], $this->headers($user));
    }

    protected function post(array $body = [], $user = null, array $query = [])
    {
        return new Request('POST', $body, $query, $user, [], $this->headers($user));
    }

    protected function put(array $body = [], $user = null, array $query = [])
    {
        return new Request('PUT', $body, $query, $user, [], $this->headers($user));
    }

    protected function delete(array $query = [], $user = null)
    {
        return new Request('DELETE', [], $query, $user, [], $this->headers($user));
    }

    private function headers($user)
    {
        return $user === null ? [] : ['Authorization' => 'Bearer token-de-prueba'];
    }

    protected function assertStatus($esperado, Response $res)
    {
        $this->assertSame($esperado, $res->status, 'Respuesta: ' . json_encode($res->body, JSON_UNESCAPED_UNICODE));
    }
}
