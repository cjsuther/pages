<?php

namespace Tests\Unit\Lib;

use BajasDeCorreo;
use Tests\Support\HandlerTestCase;

class BajasDeCorreoTest extends HandlerTestCase
{
    public function testElTokenVaYVuelveConElEmailEnMinusculas()
    {
        $token = BajasDeCorreo::token(5, ' Ana@Example.com ');

        $this->assertSame(['page_id' => 5, 'email' => 'ana@example.com'], BajasDeCorreo::leerToken($token));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $token, 'se puede poner en una URL tal cual');
    }

    public function testUnTokenTocadoNoVale()
    {
        $token = BajasDeCorreo::token(5, 'ana@example.com');
        list(, $firma) = explode('.', $token);

        // Mismo email, otra página: la firma no cierra.
        $otraPagina = rtrim(strtr(base64_encode('6:ana@example.com'), '+/', '-_'), '=') . '.' . $firma;

        $this->assertNull(BajasDeCorreo::leerToken($otraPagina));
        $this->assertNull(BajasDeCorreo::leerToken($token . 'x'));
        $this->assertNull(BajasDeCorreo::leerToken('nada'));
        $this->assertNull(BajasDeCorreo::leerToken(null));
        $this->assertNull(BajasDeCorreo::leerToken(['a.b']));
    }

    public function testLasUrlsYLasCabeceras()
    {
        $token = BajasDeCorreo::token(5, 'ana@example.com');

        $this->assertSame('https://frontend.test/baja?t=' . $token, BajasDeCorreo::urlPagina($token));
        $this->assertSame([
            'List-Unsubscribe' => '<https://rezonar.test/api/public/baja.php?t=' . $token . '>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ], BajasDeCorreo::cabeceras($token));
    }

    public function testDarLaBajaGuardaElEmailEnMinusculas()
    {
        BajasDeCorreo::dar($this->db, 5, 'Ana@Example.com');

        $this->assertSame([5, 'ana@example.com'], $this->db->paramsFor('INSERT IGNORE INTO bajas_de_correo'));
    }

    public function testLasBajasDeLaPaginaSonUnConjunto()
    {
        $this->db->onSelect('SELECT email FROM bajas_de_correo', [['email' => 'ana@example.com'], ['email' => 'Beto@Example.com']]);

        $this->assertSame(['ana@example.com' => true, 'beto@example.com' => true], BajasDeCorreo::dePagina($this->db, 5));
        $this->assertSame([5], $this->db->paramsFor('SELECT email FROM bajas_de_correo'));
    }
}
