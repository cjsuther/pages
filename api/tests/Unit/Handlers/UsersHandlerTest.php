<?php

namespace Tests\Unit\Handlers;

use Request;
use Tests\Support\HandlerTestCase;
use UsersHandler;

class UsersHandlerTest extends HandlerTestCase
{
    // ================================================================ profile

    public function testProfileExigeSesion()
    {
        $this->assertError(401, UsersHandler::profile($this->db, $this->get()), 'Unauthorized');
    }

    public function testProfileDevuelveLosDatosDelUsuario()
    {
        $this->db->onSelect('SELECT id, email, name FROM users WHERE id = ?', [
            ['id' => 9, 'email' => 'a@b.com', 'name' => 'Ana'],
        ]);

        $res = UsersHandler::profile($this->db, $this->get([], $this->user(9)));

        $this->assertStatus(200, $res);
        $this->assertSame(['id' => 9, 'email' => 'a@b.com', 'name' => 'Ana'], $res->body['user']);
        $this->assertSame([9], $this->db->paramsFor('SELECT id, email, name FROM users'));
    }

    public function testProfileNoDevuelveLaPassword()
    {
        UsersHandler::profile($this->db, $this->get([], $this->user(9)));

        $sql = $this->db->callsFor('FROM users WHERE id = ?')[0]['sql'];

        $this->assertStringNotContainsString('password', $sql);
    }

    public function testActualizarNombre()
    {
        $this->db->onWrite('UPDATE users SET name = ?', 1);

        $res = UsersHandler::profile($this->db, $this->put(['name' => 'Ana Gómez'], $this->user(9)));

        $this->assertStatus(200, $res);
        $this->assertTrue($res->body['success']);
        $this->assertSame('Ana Gómez', $res->body['user']['name']);
        $this->assertSame(['Ana Gómez', 9], $this->db->paramsFor('UPDATE users SET name = ?'));
    }

    public function testActualizarNombreRecortaEspacios()
    {
        $this->db->onWrite('UPDATE users SET name = ?', 1);

        UsersHandler::profile($this->db, $this->put(['name' => '  Ana  '], $this->user(9)));

        $this->assertSame('Ana', $this->db->paramsFor('UPDATE users SET name = ?')[0]);
    }

    public function testNombreVacioSeGuardaComoNull()
    {
        $this->db->onWrite('UPDATE users SET name = ?', 1);

        $res = UsersHandler::profile($this->db, $this->put(['name' => '   '], $this->user(9)));

        $this->assertNull($this->db->paramsFor('UPDATE users SET name = ?')[0]);
        $this->assertNull($res->body['user']['name']);
    }

    public function testRechazaNombreDemasiadoLargo()
    {
        $res = UsersHandler::profile($this->db, $this->put(
            ['name' => str_repeat('a', 256)],
            $this->user(9)
        ));

        $this->assertError(400, $res, 'no puede superar los 255 caracteres');
        $this->assertNoWrites();
    }

    public function testAceptaNombreDeExactamente255()
    {
        $this->db->onWrite('UPDATE users SET name = ?', 1);

        $res = UsersHandler::profile($this->db, $this->put(
            ['name' => str_repeat('a', 255)],
            $this->user(9)
        ));

        $this->assertStatus(200, $res);
    }

    public function testProfileRechazaMetodoNoSoportado()
    {
        $res = UsersHandler::profile($this->db, $this->delete([], $this->user()));

        $this->assertError(405, $res, 'Método no permitido');
    }

    // =============================================================== location

    public function testLocationExigeCabeceraAuthorization()
    {
        $res = UsersHandler::location($this->db, new Request('GET'));

        $this->assertError(401, $res, 'Token no proporcionado');
    }

    public function testLocationDistingueTokenInvalido()
    {
        $res = UsersHandler::location($this->db, $this->conTokenInvalido('GET'));

        $this->assertError(401, $res, 'Unauthorized');
    }

    public function testVerUbicacionDevuelve404SiNoExisteElUsuario()
    {
        $res = UsersHandler::location($this->db, $this->get([], $this->user(9)));

        $this->assertError(404, $res, 'Usuario no encontrado');
    }

    public function testVerUbicacionConvierteLasCoordenadasAFloat()
    {
        $this->db->onSelect('SELECT location_latitude', [[
            'location_latitude' => '-34.6037',
            'location_longitude' => '-58.3816',
            'location_name' => 'Buenos Aires',
            'last_location_update' => '2026-01-01 10:00:00',
        ]]);

        $res = UsersHandler::location($this->db, $this->get([], $this->user(9)));

        $this->assertStatus(200, $res);
        $this->assertSame(-34.6037, $res->body['latitude']);
        $this->assertSame(-58.3816, $res->body['longitude']);
        $this->assertSame('Buenos Aires', $res->body['location_name']);
    }

    public function testVerUbicacionDevuelveNullSiNoHayCoordenadas()
    {
        $this->db->onSelect('SELECT location_latitude', [[
            'location_latitude' => null,
            'location_longitude' => null,
            'location_name' => null,
            'last_location_update' => null,
        ]]);

        $res = UsersHandler::location($this->db, $this->get([], $this->user(9)));

        $this->assertNull($res->body['latitude']);
        $this->assertNull($res->body['longitude']);
    }

    public function testGuardarUbicacionExigeLatitudYLongitud()
    {
        $res = UsersHandler::location($this->db, $this->post(['latitude' => -34.6], $this->user(9)));

        $this->assertError(400, $res, 'Latitud y longitud son requeridas');
        $this->assertNoWrites();
    }

    /**
     * @dataProvider coordenadasFueraDeRango
     */
    public function testGuardarUbicacionRechazaCoordenadasInvalidas($lat, $lng)
    {
        $res = UsersHandler::location($this->db, $this->post([
            'latitude' => $lat, 'longitude' => $lng,
        ], $this->user(9)));

        $this->assertError(400, $res, 'Coordenadas inválidas');
        $this->assertNoWrites();
    }

    public function coordenadasFueraDeRango()
    {
        return [
            'latitud > 90' => [91, 0],
            'latitud < -90' => [-91, 0],
            'longitud > 180' => [0, 181],
            'longitud < -180' => [0, -181],
            'ambas fuera' => [200, 200],
        ];
    }

    /**
     * @dataProvider coordenadasLimite
     */
    public function testGuardarUbicacionAceptaLosExtremosValidos($lat, $lng)
    {
        $this->db->onWrite('UPDATE users SET location_latitude', 1);

        $res = UsersHandler::location($this->db, $this->post([
            'latitude' => $lat, 'longitude' => $lng,
        ], $this->user(9)));

        $this->assertStatus(200, $res);
    }

    public function coordenadasLimite()
    {
        return [
            'polo norte' => [90, 0],
            'polo sur' => [-90, 0],
            'antimeridiano este' => [0, 180],
            'antimeridiano oeste' => [0, -180],
            'origen' => [0, 0],
        ];
    }

    public function testGuardarUbicacionActualizaYDevuelveLosDatos()
    {
        $this->db->onWrite('UPDATE users SET location_latitude', 1);

        $res = UsersHandler::location($this->db, $this->post([
            'latitude' => -34.6037,
            'longitude' => -58.3816,
            'address' => 'Buenos Aires',
        ], $this->user(9)));

        $this->assertStatus(200, $res);
        $this->assertTrue($res->body['success']);
        $this->assertSame(-34.6037, $res->body['latitude']);
        $this->assertSame('Buenos Aires', $res->body['location_name']);
        $this->assertSame(
            [-34.6037, -58.3816, 'Buenos Aires', 9],
            $this->db->paramsFor('UPDATE users SET location_latitude')
        );
    }

    public function testGuardarUbicacionAceptaSinDireccion()
    {
        $this->db->onWrite('UPDATE users SET location_latitude', 1);

        $res = UsersHandler::location($this->db, $this->post([
            'latitude' => -34.6037, 'longitude' => -58.3816,
        ], $this->user(9)));

        $this->assertStatus(200, $res);
        $this->assertNull($res->body['location_name']);
    }

    public function testLocationRechazaMetodoNoSoportado()
    {
        $res = UsersHandler::location($this->db, $this->put([], $this->user()));

        $this->assertError(405, $res, 'Método no permitido');
    }

    /**
     * @dataProvider validacionesDeCoordenadas
     */
    public function testCoordenadasValidas($lat, $lng, $esperado)
    {
        $this->assertSame($esperado, UsersHandler::coordenadasValidas($lat, $lng));
    }

    public function validacionesDeCoordenadas()
    {
        return [
            [0, 0, true],
            [-34.6037, -58.3816, true],
            [90, 180, true],
            [-90, -180, true],
            [90.1, 0, false],
            [0, 180.1, false],
        ];
    }
}
