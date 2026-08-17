<?php

namespace Tests\Unit\Lib;

use CodigoQR;
use PHPUnit\Framework\TestCase;

class CodigoQRTest extends TestCase
{
    public function testEstaDisponibleConLaLibreriaYGd()
    {
        $this->assertTrue(CodigoQR::disponible());
    }

    public function testGeneraUnPng()
    {
        $png = CodigoQR::png('https://rezon.ar/entrada/ABC123');

        $this->assertNotNull($png);
        // Firma de un PNG: los primeros ocho bytes son siempre estos.
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($png, 0, 8));
    }

    public function testLaImagenTieneUnTamanoRazonable()
    {
        $png = CodigoQR::png('https://rezon.ar/entrada/ABC123');
        $tamano = getimagesizefromstring($png);

        $this->assertGreaterThanOrEqual(200, $tamano[0], 'muy chico para escanear de una pantalla');
        $this->assertSame($tamano[0], $tamano[1], 'un QR es cuadrado');
    }

    /**
     * El QR lleva la URL de la orden y no sólo el código: al escanearlo en la
     * puerta se ve el estado real —pagada, vencida— y no un texto suelto que
     * no dice si sigue valiendo.
     */
    public function testApuntaALaPaginaDeLaOrden()
    {
        $this->assertSame('https://frontend.test/entrada/ABC123', CodigoQR::urlDeLaOrden('ABC123'));
    }

    public function testCadaCodigoDaUnaImagenDistinta()
    {
        $this->assertNotSame(
            CodigoQR::png(CodigoQR::urlDeLaOrden('AAA')),
            CodigoQR::png(CodigoQR::urlDeLaOrden('BBB'))
        );
    }

    public function testUnContenidoVacioNoRompe()
    {
        $this->assertNull(CodigoQR::png(''));
    }
}
