<?php

namespace Tests\Unit\Lib;

use Redes;
use Tests\Support\HandlerTestCase;

class RedesTest extends HandlerTestCase
{
    // ============================================================== catálogo

    public function testElCatalogoIncluyeLasRedesConIconoPropio()
    {
        foreach (['instagram', 'tiktok', 'youtube', 'facebook', 'whatsapp', 'cafecito'] as $red) {
            $this->assertTrue(Redes::permitida($red), "$red debería estar permitida");
        }
    }

    public function testRechazaClavesFueraDelCatalogo()
    {
        $this->assertFalse(Redes::permitida('myspace'));
        $this->assertFalse(Redes::permitida(''));
        $this->assertFalse(Redes::permitida('INSTAGRAM'), 'la comparación distingue mayúsculas');
    }

    // ============================================================== lectura

    public function testDevuelveLasRedesOrdenadas()
    {
        $this->db->onSelect('FROM page_socials', [
            ['red' => 'instagram', 'url' => 'https://instagram.com/yo', 'position' => 0],
            ['red' => 'youtube', 'url' => 'https://youtube.com/@yo', 'position' => 1],
        ]);

        $redes = Redes::deLaPagina($this->db, 5);

        $this->assertCount(2, $redes);
        $this->assertSame('instagram', $redes[0]['red']);
        $this->assertStringContainsString('ORDER BY position', $this->db->callsFor('FROM page_socials')[0]['sql']);
    }

    public function testUnaPaginaSinRedesDevuelveListaVacia()
    {
        $this->assertSame([], Redes::deLaPagina($this->db, 5));
    }

    // ========================================================== sincronización

    public function testGuardaLasRedesRecibidas()
    {
        $resultado = Redes::reemplazar($this->db, 5, [
            ['red' => 'instagram', 'url' => 'https://instagram.com/yo'],
            ['red' => 'whatsapp', 'url' => 'https://wa.me/5491122334455'],
        ]);

        $this->assertSame(2, $resultado['guardadas']);
        $this->assertSame(2, $this->db->countCalls('INSERT INTO page_socials'));
    }

    /** El editor manda el estado final del formulario, no un diff. */
    public function testBorraLasAnterioresAntesDeInsertar()
    {
        Redes::reemplazar($this->db, 5, [['red' => 'instagram', 'url' => 'https://instagram.com/yo']]);

        $this->assertSame([5], $this->db->paramsFor('DELETE FROM page_socials'));
    }

    /**
     * El sentido de la sección es que en la página pública sólo se vean las
     * redes que el usuario completó: un campo vacío no se guarda.
     */
    public function testDescartaLasEntradasSinUrl()
    {
        $resultado = Redes::reemplazar($this->db, 5, [
            ['red' => 'instagram', 'url' => 'https://instagram.com/yo'],
            ['red' => 'tiktok', 'url' => ''],
            ['red' => 'youtube', 'url' => '   '],
            ['red' => 'facebook'],
        ]);

        $this->assertSame(1, $resultado['guardadas']);
        $this->assertSame(1, $this->db->countCalls('INSERT INTO page_socials'));
    }

    public function testDescartaLasRedesDesconocidas()
    {
        $resultado = Redes::reemplazar($this->db, 5, [
            ['red' => 'instagram', 'url' => 'https://instagram.com/yo'],
            ['red' => 'myspace', 'url' => 'https://myspace.com/yo'],
        ]);

        $this->assertSame(1, $resultado['guardadas']);
        $this->assertSame(['myspace'], $resultado['ignoradas']);
    }

    public function testUnaSolaCuentaPorRed()
    {
        $resultado = Redes::reemplazar($this->db, 5, [
            ['red' => 'instagram', 'url' => 'https://instagram.com/primera'],
            ['red' => 'instagram', 'url' => 'https://instagram.com/segunda'],
        ]);

        $this->assertSame(1, $resultado['guardadas']);
        $this->assertSame('https://instagram.com/primera', $this->db->paramsFor('INSERT INTO page_socials')[2]);
    }

    public function testConservaElOrdenDeLlegada()
    {
        Redes::reemplazar($this->db, 5, [
            ['red' => 'youtube', 'url' => 'https://youtube.com/@yo'],
            ['red' => 'instagram', 'url' => 'https://instagram.com/yo'],
        ]);

        $llamadas = $this->db->callsFor('INSERT INTO page_socials');

        $this->assertSame('youtube', $llamadas[0]['params'][1]);
        $this->assertSame(0, $llamadas[0]['params'][3]);
        $this->assertSame('instagram', $llamadas[1]['params'][1]);
        $this->assertSame(1, $llamadas[1]['params'][3]);
    }

    public function testUnaListaVaciaBorraTodo()
    {
        $resultado = Redes::reemplazar($this->db, 5, []);

        $this->assertSame(0, $resultado['guardadas']);
        $this->assertSame(1, $this->db->countCalls('DELETE FROM page_socials'));
        $this->assertSame(0, $this->db->countCalls('INSERT INTO page_socials'));
    }

    public function testRecortaUrlsDemasiadoLargas()
    {
        Redes::reemplazar($this->db, 5, [
            ['red' => 'web', 'url' => 'https://x.com/' . str_repeat('a', 600)],
        ]);

        $this->assertSame(
            Redes::MAX_LONGITUD_URL,
            strlen($this->db->paramsFor('INSERT INTO page_socials')[2])
        );
    }

    /** Sin transacción, un fallo a mitad dejaría la página sin redes. */
    public function testTodoOcurreDentroDeUnaTransaccion()
    {
        Redes::reemplazar($this->db, 5, [['red' => 'instagram', 'url' => 'https://instagram.com/yo']]);

        $this->assertTrue($this->db->committed);
        $this->assertSame(0, $this->db->transactionDepth);
    }

    public function testSiFallaSeDeshaceElBorrado()
    {
        $this->db->failOn('INSERT INTO page_socials', 'tabla bloqueada');

        try {
            Redes::reemplazar($this->db, 5, [['red' => 'instagram', 'url' => 'https://instagram.com/yo']]);
            $this->fail('Se esperaba una excepción');
        } catch (\Exception $e) {
            $this->assertTrue($this->db->rolledBack);
        }
    }
}
