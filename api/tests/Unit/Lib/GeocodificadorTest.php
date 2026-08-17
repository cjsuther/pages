<?php

namespace Tests\Unit\Lib;

use Geocodificador;
use Tests\Support\HandlerTestCase;

class GeocodificadorTest extends HandlerTestCase
{
    private const RESPUESTA = '[{"lat":"-34.6151736","lon":"-58.3730221","display_name":"Bolívar 624"}]';

    private function conRespuesta($cuerpo)
    {
        return new Geocodificador(function () use ($cuerpo) { return $cuerpo; });
    }

    // -------------------------------------------------------- lectura

    public function testLeeLasCoordenadasDeLaRespuesta()
    {
        $c = Geocodificador::leerRespuesta(self::RESPUESTA);

        $this->assertSame('-34.6151736', $c['latitud']);
        $this->assertSame('-58.3730221', $c['longitud']);
    }

    public function testUnaDireccionQueNoSeEncuentraDaNull()
    {
        $this->assertNull(Geocodificador::leerRespuesta('[]'));
    }

    public function testUnaRespuestaIlegibleNoRompe()
    {
        $this->assertNull(Geocodificador::leerRespuesta('no es json'));
        $this->assertNull(Geocodificador::leerRespuesta(''));
        $this->assertNull(Geocodificador::leerRespuesta(null));
    }

    public function testUnaCoordenadaNoNumericaSeRechaza()
    {
        $this->assertNull(Geocodificador::leerRespuesta('[{"lat":"ahí","lon":"por allá"}]'));
    }

    // ---------------------------------------------------- normalización

    /** Sin esto, dos escrituras de la misma dirección se geocodifican dos veces. */
    public function testLaDireccionSeNormalizaParaQueLaCacheAcierte()
    {
        $this->assertSame(
            Geocodificador::normalizar('Bolívar 624 ,  CABA'),
            Geocodificador::normalizar('Bolívar 624, CABA')
        );
    }

    public function testSeRecortaALoQueEntraEnLaColumna()
    {
        $this->assertSame(500, mb_strlen(Geocodificador::normalizar(str_repeat('a', 900))));
    }

    // ------------------------------------------------------------ caché

    public function testUnaDireccionYaResueltaSaleDeLaCacheSinPreguntar()
    {
        $this->db->onSelect('FROM geocode_cache WHERE huella', [[
            'latitud' => '-34.6151736', 'longitud' => '-58.3730221', 'intentos' => 1,
        ]]);

        $pedidos = 0;
        $geo = new Geocodificador(function () use (&$pedidos) { $pedidos++; return self::RESPUESTA; });

        $c = $geo->coordenadas($this->db, 'Bolívar 624, CABA');

        $this->assertSame('-34.6151736', $c['latitud']);
        $this->assertSame(0, $pedidos, 'no tiene que salir a la red');
    }

    public function testUnaDireccionNuevaSePreguntaYSeGuarda()
    {
        $this->db->onWrite('INSERT INTO geocode_cache', 1);

        $c = $this->conRespuesta(self::RESPUESTA)->coordenadas($this->db, 'Bolívar 624, CABA');

        $this->assertSame('-34.6151736', $c['latitud']);
        $this->assertTrue($this->db->ran('INSERT INTO geocode_cache'));
    }

    /**
     * Los fallos también se cachean: sin eso, una dirección que el servicio no
     * sabe resolver se volvería a preguntar en cada corrida, todas las noches.
     */
    public function testUnFalloSeGuardaParaNoReintentarEternamente()
    {
        $this->db->onWrite('INSERT INTO geocode_cache', 1);

        $c = $this->conRespuesta('[]')->coordenadas($this->db, 'Dirección inventada');

        $this->assertNull($c);
        $this->assertTrue($this->db->ran('INSERT INTO geocode_cache'));
    }

    public function testUnFalloConLosIntentosAgotadosNoVuelveAPreguntar()
    {
        $this->db->onSelect('FROM geocode_cache WHERE huella', [[
            'latitud' => null, 'longitud' => null, 'intentos' => Geocodificador::MAX_INTENTOS,
        ]]);

        $pedidos = 0;
        $geo = new Geocodificador(function () use (&$pedidos) { $pedidos++; return self::RESPUESTA; });

        $this->assertNull($geo->coordenadas($this->db, 'Dirección imposible'));
        $this->assertSame(0, $pedidos);
    }

    /** Un fallo aislado puede haber sido del servicio: se reintenta una vez. */
    public function testUnFalloConIntentosDisponiblesSeReintenta()
    {
        $this->db->onSelect('FROM geocode_cache WHERE huella', [[
            'latitud' => null, 'longitud' => null, 'intentos' => 1,
        ]]);
        $this->db->onWrite('INSERT INTO geocode_cache', 1);

        $c = $this->conRespuesta(self::RESPUESTA)->coordenadas($this->db, 'Bolívar 624');

        $this->assertNotNull($c);
    }

    public function testUnaDireccionVaciaNoConsultaNada()
    {
        $pedidos = 0;
        $geo = new Geocodificador(function () use (&$pedidos) { $pedidos++; return self::RESPUESTA; });

        $this->assertNull($geo->coordenadas($this->db, '   '));
        $this->assertSame(0, $pedidos);
    }
}
