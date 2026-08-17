<?php

namespace Tests\Unit\Lib;

use ClavesApi;
use Tests\Support\HandlerTestCase;

class ClavesApiTest extends HandlerTestCase
{
    private function hayClave(array $overrides = [])
    {
        $this->db->onSelect('FROM api_keys k', [array_merge([
            'id' => 3, 'user_id' => 7, 'email' => 'ana@example.com', 'name' => 'Ana',
        ], $overrides)]);
        $this->db->onWrite('UPDATE api_keys SET ultimo_uso_en', 1);
    }

    // ------------------------------------------------------------- generar

    public function testGeneraUnaClaveConElPrefijoDeRezonar()
    {
        $this->db->onSelect('SELECT COUNT(*) FROM api_keys', [[0]]);
        $this->db->onWrite('INSERT INTO api_keys', 1);

        $r = ClavesApi::generar($this->db, 7, 'Mi asistente');

        $this->assertTrue($r['ok']);
        $this->assertStringStartsWith('rzn_', $r['clave']);
    }

    /** Dos claves seguidas no pueden salir iguales. */
    public function testCadaClaveEsDistinta()
    {
        $this->db->onSelect('SELECT COUNT(*) FROM api_keys', [[0]]);
        $this->db->onWrite('INSERT INTO api_keys', 1);
        $una = ClavesApi::generar($this->db, 7, 'A')['clave'];

        $this->db->onSelect('SELECT COUNT(*) FROM api_keys', [[0]]);
        $this->db->onWrite('INSERT INTO api_keys', 1);
        $otra = ClavesApi::generar($this->db, 7, 'B')['clave'];

        $this->assertNotSame($una, $otra);
    }

    /**
     * De la clave sólo se guarda el hash: si alguien se lleva la base, se
     * lleva hashes y no credenciales.
     */
    public function testNoSeGuardaLaClaveEnClaro()
    {
        $this->db->onSelect('SELECT COUNT(*) FROM api_keys', [[0]]);
        $this->db->onWrite('INSERT INTO api_keys', 1);

        $r = ClavesApi::generar($this->db, 7, 'Mi asistente');
        $guardados = $this->db->paramsFor('INSERT INTO api_keys');

        $this->assertNotContains($r['clave'], $guardados);
        $this->assertContains(ClavesApi::hash($r['clave']), $guardados);
    }

    /** El prefijo sí se guarda, para poder reconocer cuál se está revocando. */
    public function testSeGuardaElPrefijoParaPoderMostrarlo()
    {
        $this->db->onSelect('SELECT COUNT(*) FROM api_keys', [[0]]);
        $this->db->onWrite('INSERT INTO api_keys', 1);

        $r = ClavesApi::generar($this->db, 7, 'Mi asistente');

        $this->assertContains(substr($r['clave'], 0, ClavesApi::LARGO_PREFIJO), $this->db->paramsFor('INSERT INTO api_keys'));
    }

    public function testUnaClaveSinNombreNoSeCrea()
    {
        $r = ClavesApi::generar($this->db, 7, '   ');

        $this->assertFalse($r['ok']);
        $this->assertSame(0, $this->db->countCalls('INSERT INTO api_keys'));
    }

    /** Una integración con un bug no puede llenar la tabla. */
    public function testHayUnTopePorUsuario()
    {
        $this->db->onSelect('SELECT COUNT(*) FROM api_keys', [[ClavesApi::MAX_POR_USUARIO]]);

        $r = ClavesApi::generar($this->db, 7, 'Otra más');

        $this->assertFalse($r['ok']);
        $this->assertSame(0, $this->db->countCalls('INSERT INTO api_keys'));
    }

    // ------------------------------------------------------------- usuario

    public function testUnaClaveValidaIdentificaASuDuenio()
    {
        $this->hayClave();

        $u = ClavesApi::usuario($this->db, 'rzn_' . str_repeat('a', 64));

        $this->assertSame(7, $u['user_id']);
        $this->assertSame('ana@example.com', $u['email']);
    }

    /**
     * La sesión queda marcada como venida de una clave: es lo que después
     * impide que una credencial se fabrique otras.
     */
    public function testLaSesionQuedaMarcadaComoDeClaveApi()
    {
        $this->hayClave();

        $u = ClavesApi::usuario($this->db, 'rzn_' . str_repeat('a', 64));

        $this->assertTrue($u['por_clave_api']);
    }

    public function testSeAnotaElUltimoUso()
    {
        $this->hayClave();

        ClavesApi::usuario($this->db, 'rzn_' . str_repeat('a', 64));

        $this->assertSame(1, $this->db->countCalls('UPDATE api_keys SET ultimo_uso_en'));
    }

    /** Sin el prefijo no vale la pena ni consultar la base. */
    public function testUnaClaveConOtroFormatoNiSeConsulta()
    {
        $this->assertNull(ClavesApi::usuario($this->db, 'algo-que-no-es-una-clave'));
        $this->assertSame(0, $this->db->countCalls('FROM api_keys k'));
    }

    public function testUnaClaveQueNoExisteNoIdentificaANadie()
    {
        $this->db->onSelect('FROM api_keys k', []);

        $this->assertNull(ClavesApi::usuario($this->db, 'rzn_' . str_repeat('b', 64)));
    }

    /** La consulta filtra las revocadas: una clave dada de baja no entra. */
    public function testLaConsultaExcluyeLasRevocadas()
    {
        $this->db->onSelect('FROM api_keys k', []);

        ClavesApi::usuario($this->db, 'rzn_' . str_repeat('b', 64));

        $this->assertStringContainsString('revocada_en IS NULL', $this->db->callsFor('FROM api_keys k')[0]['sql']);
    }

    // ------------------------------------------------------------- revocar

    /** Se revoca y no se borra: queda el registro de que existió. */
    public function testRevocarNoBorraLaFila()
    {
        $this->db->onWrite('UPDATE api_keys', 1);

        $this->assertTrue(ClavesApi::revocar($this->db, 7, 3));
        $this->assertSame(0, $this->db->countCalls('DELETE FROM api_keys'));
    }

    /** Nadie puede revocar la clave de otro. */
    public function testRevocarExigeQueLaClaveSeaTuya()
    {
        $this->db->onWrite('UPDATE api_keys', 0);

        $this->assertFalse(ClavesApi::revocar($this->db, 7, 3));
        $this->assertContains(7, $this->db->paramsFor('UPDATE api_keys'));
    }
}
