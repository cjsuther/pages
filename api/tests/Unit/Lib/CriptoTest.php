<?php

namespace Tests\Unit\Lib;

use Cripto;
use PHPUnit\Framework\TestCase;

class CriptoTest extends TestCase
{
    const TOKEN = 'APP_USR-1234567890123456-081612-abcdef0123456789abcdef0123456789-123456789';

    public function testLoCifradoSeRecuperaIgual()
    {
        $this->assertSame(self::TOKEN, Cripto::descifrar(Cripto::cifrar(self::TOKEN)));
    }

    public function testLoCifradoNoContieneElTextoOriginal()
    {
        $this->assertStringNotContainsString('APP_USR', Cripto::cifrar(self::TOKEN));
    }

    /**
     * Cada cifrado usa un IV nuevo. Si no, dos páginas con la misma credencial
     * darían el mismo texto guardado y eso ya filtra información.
     */
    public function testCifrarDosVecesDaResultadosDistintos()
    {
        $this->assertNotSame(Cripto::cifrar(self::TOKEN), Cripto::cifrar(self::TOKEN));
    }

    public function testAmbosCifradosSeDescifranAlMismoTexto()
    {
        $a = Cripto::cifrar(self::TOKEN);
        $b = Cripto::cifrar(self::TOKEN);

        $this->assertSame(Cripto::descifrar($a), Cripto::descifrar($b));
    }

    /**
     * GCM autentica además de cifrar: un texto manipulado tiene que fallar al
     * descifrar en vez de devolver basura que después se mande a Mercado Pago.
     */
    public function testUnTextoManipuladoNoSeDescifra()
    {
        $cifrado = Cripto::cifrar(self::TOKEN);
        $manipulado = substr($cifrado, 0, -6) . 'AAAAAA';

        $this->assertNull(Cripto::descifrar($manipulado));
    }

    public function testUnTextoSinElPrefijoDeVersionNoSeDescifra()
    {
        $this->assertNull(Cripto::descifrar(base64_encode('cualquier cosa')));
    }

    public function testUnTextoDemasiadoCortoNoRompe()
    {
        $this->assertNull(Cripto::descifrar('v1:' . base64_encode('corto')));
    }

    public function testDescifrarBasuraDevuelveNull()
    {
        $this->assertNull(Cripto::descifrar('no es nada'));
        $this->assertNull(Cripto::descifrar(''));
    }

    public function testSeCifranTextosConAcentosYSimbolos()
    {
        $texto = 'ñandú-€-🎫-TEST-1234567890';

        $this->assertSame($texto, Cripto::descifrar(Cripto::cifrar($texto)));
    }

    public function testLosUltimosCuatroSonParaReconocerLaCredencial()
    {
        $this->assertSame('6789', Cripto::ultimos4('APP_USR-123456789'));
    }

    public function testDisponibleCuandoHayClaveYOpenssl()
    {
        $this->assertTrue(Cripto::disponible());
    }
}
