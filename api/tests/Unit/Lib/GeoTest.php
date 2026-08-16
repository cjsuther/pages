<?php

namespace Tests\Unit\Lib;

use Geo;
use PHPUnit\Framework\TestCase;

class GeoTest extends TestCase
{
    public function testDistanciaEntreElMismoPuntoEsCero()
    {
        $this->assertSame(0.0, Geo::distanceKm(-34.6037, -58.3816, -34.6037, -58.3816));
    }

    public function testDistanciaConocidaBuenosAiresCordoba()
    {
        // ~646 km en línea recta.
        $d = Geo::distanceKm(-34.6037, -58.3816, -31.4201, -64.1888);

        $this->assertEqualsWithDelta(646, $d, 10);
    }

    public function testDistanciaConocidaBuenosAiresMontevideo()
    {
        // ~205 km cruzando el Río de la Plata.
        $d = Geo::distanceKm(-34.6037, -58.3816, -34.9011, -56.1645);

        $this->assertEqualsWithDelta(205, $d, 10);
    }

    public function testDistanciaCortaDentroDeLaCiudad()
    {
        $d = Geo::distanceKm(-34.6037, -58.3816, -34.5875, -58.3974);

        $this->assertGreaterThan(1, $d);
        $this->assertLessThan(5, $d);
    }

    public function testEsSimetrica()
    {
        $ida = Geo::distanceKm(-34.6037, -58.3816, -31.4201, -64.1888);
        $vuelta = Geo::distanceKm(-31.4201, -64.1888, -34.6037, -58.3816);

        $this->assertEqualsWithDelta($ida, $vuelta, 0.0001);
    }

    public function testAceptaCoordenadasComoString()
    {
        // MySQL devuelve los DECIMAL como string.
        $conStrings = Geo::distanceKm('-34.6037', '-58.3816', '-31.4201', '-64.1888');
        $conFloats = Geo::distanceKm(-34.6037, -58.3816, -31.4201, -64.1888);

        $this->assertSame($conFloats, $conStrings);
    }

    public function testCruzaElEcuadorCorrectamente()
    {
        // Quito (~0°) a Lima: ~1300 km.
        $d = Geo::distanceKm(-0.1807, -78.4678, -12.0464, -77.0428);

        $this->assertEqualsWithDelta(1320, $d, 50);
    }

    public function testCruzaElAntimeridiano()
    {
        // Dos puntos separados por la línea de cambio de fecha, cerca entre sí
        // en el globo pero muy lejos en longitud numérica.
        $d = Geo::distanceKm(0, 179.5, 0, -179.5);

        $this->assertLessThan(120, $d, 'La fórmula debe tomar el arco corto');
    }

    public function testDistanciaMaximaEsMediaCircunferencia()
    {
        // Polo a polo: media vuelta a la Tierra.
        $d = Geo::distanceKm(90, 0, -90, 0);

        $this->assertEqualsWithDelta(M_PI * Geo::EARTH_RADIUS_KM, $d, 1);
    }
}
