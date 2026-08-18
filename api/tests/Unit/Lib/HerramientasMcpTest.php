<?php

namespace Tests\Unit\Lib;

use HerramientasMcp;
use InvalidArgumentException;
use Tests\Support\HandlerTestCase;

class HerramientasMcpTest extends HandlerTestCase
{
    private $usuario = ['user_id' => 7, 'email' => 'ana@example.com', 'name' => 'Ana', 'por_clave_api' => true];

    private function correr($nombre, array $args = [], $storage = null)
    {
        return HerramientasMcp::ejecutar($this->db, $this->usuario, $nombre, $args, $storage);
    }

    /** Bytes de una imagen real: la validación la decodifica entera. */
    private function pngDeVerdad()
    {
        $im = imagecreatetruecolor(12, 12);
        ob_start();
        imagepng($im);
        imagedestroy($im);

        return ob_get_clean();
    }

    /** Almacenamiento que no toca el disco. */
    private function discoFalso($info = [10, 10, IMAGETYPE_JPEG], $exito = true)
    {
        return new class($info, $exito) extends \FileStorage {
            public $destino;
            private $info;
            private $exito;

            public function __construct($info, $exito)
            {
                $this->info = $info;
                $this->exito = $exito;
            }

            public function imageInfo($path) { return $this->info; }
            public function ensureDir($dir) {}
            public function guardarTemporal($contenido) { return '/tmp/falso'; }
            public function mover($origen, $destino) { $this->destino = $destino; return $this->exito; }
            public function borrar($ruta) {}
        };
    }

    private function sePuedeCrearElEvento()
    {
        $this->laPaginaEsSuya();
        $this->db->onSelect('FROM link_groups WHERE page_id', [[20]]);
        $this->geocodificacionQueAnda();
        $this->db->onSelect('SELECT 1 FROM link_groups lg', [[1]]);
        $this->db->onSelect('SELECT id, type FROM link_groups', [['id' => 20, 'type' => 'links']]);
        $this->db->onWrite('INSERT INTO links', 1);
        $this->db->onSelect('SELECT * FROM links WHERE id', [['id' => 300]]);
    }

    /** La página existe y es de esta persona. */
    private function laPaginaEsSuya($pageId = 5)
    {
        $this->db->onSelect('FROM pages WHERE url_slug', [[$pageId]]);
        // PageAccess::canManage: una fila cualquiera significa "sí puede".
        $this->db->onSelect('SELECT 1 FROM pages p', [[1]]);
    }

    private function geocodificacionQueAnda()
    {
        $this->db->onSelect('FROM geocode_cache WHERE huella', [[
            'latitud' => '-34.60', 'longitud' => '-58.38', 'intentos' => 0,
        ]]);
    }

    // -------------------------------------------------------------- catálogo

    public function testUnaHerramientaQueNoExisteRompeFuerte()
    {
        $this->expectException(InvalidArgumentException::class);

        $this->correr('volar');
    }

    /** El nombre del catálogo y el que se puede ejecutar tienen que coincidir. */
    public function testTodasLasHerramientasDelCatalogoSePuedenEjecutar()
    {
        foreach (HerramientasMcp::catalogo() as $herramienta) {
            try {
                $this->correr($herramienta['name']);
            } catch (InvalidArgumentException $e) {
                $this->fail("'{$herramienta['name']}' está en el catálogo pero no se puede ejecutar");
            } catch (\Throwable $e) {
                // Falla por falta de argumentos: eso es esperable acá.
            }
        }

        $this->addToAssertionCount(1);
    }

    // --------------------------------------------------------------- páginas

    public function testListarPaginasDevuelveLasDeEsaPersona()
    {
        $this->db->onSelect('FROM pages WHERE user_id', [['id' => 5, 'pagina' => 'mi-pagina']]);

        $r = $this->correr('listar_paginas');

        $this->assertTrue($r['ok']);
        $this->assertCount(1, $r['datos']['paginas']);
        $this->assertContains(7, $this->db->paramsFor('FROM pages WHERE user_id'));
    }

    public function testListarEventosDeUnaPaginaQueNoExiste()
    {
        $this->db->onSelect('FROM pages WHERE url_slug', []);

        $r = $this->correr('listar_eventos', ['pagina' => 'fantasma']);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('fantasma', $r['datos']['error']);
    }

    /** La clave da acceso a las páginas de su dueño, no a las de cualquiera. */
    public function testNoSePuedeTrabajarSobreLaPaginaDeOtro()
    {
        $this->db->onSelect('FROM pages WHERE url_slug', [[5]]);
        $this->db->onSelect('SELECT 1 FROM pages p', []);

        $r = $this->correr('listar_eventos', ['pagina' => 'ajena']);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('No administrás', $r['datos']['error']);
    }

    // --------------------------------------------------------- crear evento

    /**
     * La API exige coordenadas y en el editor las pone el mapa. Un asistente
     * tiene una dirección escrita, así que se geocodifica acá.
     */
    public function testCrearUnEventoGeocodificaLaDireccion()
    {
        $this->laPaginaEsSuya();
        $this->db->onSelect('FROM link_groups WHERE page_id', [[20]]);
        $this->geocodificacionQueAnda();
        $this->db->onSelect('SELECT 1 FROM link_groups lg', [[1]]);
        $this->db->onSelect('SELECT id, type FROM link_groups', [['id' => 20, 'type' => 'links']]);
        $this->db->onWrite('INSERT INTO links', 1);
        $this->db->onSelect('SELECT * FROM links WHERE id', [['id' => 300, 'text' => 'Mi show']]);

        $r = $this->correr('crear_evento', [
            'pagina' => 'mi-pagina', 'titulo' => 'Mi show',
            'fecha' => '2026-12-01', 'hora' => '21:00', 'direccion' => 'Bolívar 624, CABA',
        ]);

        $this->assertTrue($r['ok']);

        $guardados = $this->db->paramsFor('INSERT INTO links');
        $this->assertContains('-34.60', $guardados);
        $this->assertContains('-58.38', $guardados);
    }

    public function testLaHoraSeNormalizaAlFormatoDeLaColumna()
    {
        $this->laPaginaEsSuya();
        $this->db->onSelect('FROM link_groups WHERE page_id', [[20]]);
        $this->geocodificacionQueAnda();
        $this->db->onSelect('SELECT 1 FROM link_groups lg', [[1]]);
        $this->db->onSelect('SELECT id, type FROM link_groups', [['id' => 20, 'type' => 'links']]);
        $this->db->onWrite('INSERT INTO links', 1);
        $this->db->onSelect('SELECT * FROM links WHERE id', [['id' => 300]]);

        $this->correr('crear_evento', [
            'pagina' => 'mi-pagina', 'titulo' => 'Mi show',
            'fecha' => '2026-12-01', 'hora' => '9:05', 'direccion' => 'Bolívar 624',
        ]);

        $this->assertContains('09:05:00', $this->db->paramsFor('INSERT INTO links'));
    }

    /** Sin dirección no hay punto en el mapa, y el evento no se puede publicar. */
    public function testCrearUnEventoSinDireccionSeExplica()
    {
        $this->laPaginaEsSuya();

        $r = $this->correr('crear_evento', [
            'pagina' => 'mi-pagina', 'titulo' => 'Mi show', 'fecha' => '2026-12-01',
        ]);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('dirección', $r['datos']['error']);
        $this->assertSame(0, $this->db->countCalls('INSERT INTO links'));
    }

    /** Una dirección que el mapa no encuentra se dice con qué probar. */
    public function testUnaDireccionQueNoSeUbicaSeExplica()
    {
        $this->laPaginaEsSuya();
        $this->db->onSelect('FROM geocode_cache WHERE huella', [[
            'latitud' => null, 'longitud' => null, 'intentos' => 5,
        ]]);

        $r = $this->correr('crear_evento', [
            'pagina' => 'mi-pagina', 'titulo' => 'Mi show',
            'fecha' => '2026-12-01', 'direccion' => 'por ahí',
        ]);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('mapa', $r['datos']['error']);
    }

    // ---------------------------------------------------- actualizar evento

    /** Sólo se tocan los campos que llegaron. */
    public function testActualizarMandaSoloLoQueCambio()
    {
        $this->db->onSelect('SELECT 1 FROM links l', [[1]]);
        $this->db->onSelect('SELECT event_latitude, event_longitude FROM links', [[
            'event_latitude' => '-34.60', 'event_longitude' => '-58.38',
        ]]);
        $this->db->onWrite('UPDATE links', 1);
        $this->db->onSelect('SELECT * FROM links WHERE id', [['id' => 300, 'text' => 'Nuevo título']]);

        $r = $this->correr('actualizar_evento', ['evento_id' => 300, 'titulo' => 'Nuevo título']);

        $this->assertTrue($r['ok']);

        $sql = $this->db->callsFor('UPDATE links')[0]['sql'];
        $this->assertStringContainsString('text', $sql);
        $this->assertStringNotContainsString('event_date', $sql);
    }

    public function testActualizarSinNingunCampoNoHaceNada()
    {
        $r = $this->correr('actualizar_evento', ['evento_id' => 300]);

        $this->assertFalse($r['ok']);
        $this->assertSame(0, $this->db->countCalls('UPDATE links'));
    }

    /**
     * Mover el evento de dirección sin mover el punto dejaría la ficha
     * diciendo una cosa y el mapa otra.
     */
    public function testCambiarLaDireccionRecalculaLasCoordenadas()
    {
        $this->geocodificacionQueAnda();
        $this->db->onSelect('SELECT 1 FROM links l', [[1]]);
        $this->db->onSelect('SELECT event_latitude, event_longitude FROM links', [[
            'event_latitude' => '-30', 'event_longitude' => '-50',
        ]]);
        $this->db->onWrite('UPDATE links', 1);
        $this->db->onSelect('SELECT * FROM links WHERE id', [['id' => 300]]);

        $this->correr('actualizar_evento', ['evento_id' => 300, 'direccion' => 'Bolívar 624']);

        $this->assertContains('-34.60', $this->db->paramsFor('UPDATE links'));
    }

    // --------------------------------------------------------- entradas

    public function testConfigurarEntradasExigeUnModoConocido()
    {
        $r = $this->correr('configurar_entradas', ['evento_id' => 300, 'modo' => 'regalar']);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('gratis', $r['datos']['error']);
    }

    public function testElModoPagoExigePrecio()
    {
        $r = $this->correr('configurar_entradas', ['evento_id' => 300, 'modo' => 'pago']);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('precio', $r['datos']['error']);
    }

    /** Una reserva sin costo no necesita Mercado Pago conectado. */
    public function testElModoGratisNoPideMercadoPago()
    {
        $this->db->onSelect('SELECT 1 FROM links l', [[1]]);
        $this->db->onSelect('lg.page_id', [[5]]);
        $this->db->onSelect('FROM event_ticketing', [[]]);
        $this->db->onWrite('INSERT INTO event_ticketing', 1);

        $r = $this->correr('configurar_entradas', ['evento_id' => 300, 'modo' => 'gratis', 'capacidad' => 50]);

        $this->assertTrue($r['ok'], json_encode($r['datos']));
    }

    // ----------------------------------------------------------- las ventas

    /** Los permisos son los del editor: sobre un evento ajeno no se puede. */
    public function testNoSePuedenVerLasVentasDeUnEventoAjeno()
    {
        $this->db->onSelect('SELECT 1 FROM links l', []);

        $r = $this->correr('ver_ventas', ['evento_id' => 300]);

        $this->assertFalse($r['ok']);
    }

    public function testCancelarUnaCompraDeUnEventoAjenoNoSePuede()
    {
        $this->db->onSelect('FROM ticket_orders o', [[
            'id' => 1, 'codigo' => 'ABC123', 'link_id' => 300, 'estado' => 'pagada', 'cantidad' => 2,
        ]]);
        $this->db->onSelect('SELECT 1 FROM links l', []);

        $r = $this->correr('cancelar_compra', ['codigo' => 'ABC123']);

        $this->assertFalse($r['ok']);
    }

    // ================================================== la imagen del evento

    private function eventoConImagen(array $extra = [])
    {
        return array_merge([
            'pagina' => 'mi-pagina', 'titulo' => 'Mi show',
            'fecha' => '2026-12-01', 'direccion' => 'Bolívar 624',
        ], $extra);
    }

    /**
     * Un asistente no puede mandar un formulario con un archivo: manda los
     * bytes, y el evento tiene que quedar apuntando a una imagen nuestra.
     */
    public function testCrearUnEventoConLaImagenSubidaEnBase64()
    {
        $this->sePuedeCrearElEvento();

        $r = $this->correr(
            'crear_evento',
            $this->eventoConImagen(['imagen' => base64_encode($this->pngDeVerdad())]),
            $this->discoFalso()
        );

        $this->assertTrue($r['ok'], json_encode($r['datos']));

        $guardados = $this->db->paramsFor('INSERT INTO links');
        $urls = array_filter($guardados, function ($v) {
            return is_string($v) && strpos($v, '/uploads/') !== false;
        });

        $this->assertNotEmpty($urls, 'el evento tiene que quedar con la imagen subida');
    }

    /** Sigue sirviendo apuntar a un afiche ya publicado en otro lado. */
    public function testCrearUnEventoConLaUrlDeUnAficheYaPublicado()
    {
        $this->sePuedeCrearElEvento();

        $r = $this->correr('crear_evento', $this->eventoConImagen(['imagen_url' => 'https://x/afiche.jpg']));

        $this->assertTrue($r['ok']);
        $this->assertContains('https://x/afiche.jpg', $this->db->paramsFor('INSERT INTO links'));
    }

    /** Si vienen las dos, gana la que se sube: es la de este momento. */
    public function testLaImagenSubidaLeGanaALaUrl()
    {
        $this->sePuedeCrearElEvento();

        $this->correr(
            'crear_evento',
            $this->eventoConImagen(['imagen' => base64_encode($this->pngDeVerdad()), 'imagen_url' => 'https://x/vieja.jpg']),
            $this->discoFalso()
        );

        $this->assertNotContains('https://x/vieja.jpg', $this->db->paramsFor('INSERT INTO links'));
    }

    /** Un archivo que no es una imagen no puede crear el evento a medias. */
    public function testSiLaImagenNoSirveNoSeCreaElEvento()
    {
        $this->laPaginaEsSuya();
        $this->db->onSelect('FROM link_groups WHERE page_id', [[20]]);

        $r = $this->correr(
            'crear_evento',
            $this->eventoConImagen(['imagen' => base64_encode($this->pngDeVerdad())]),
            $this->discoFalso(false)
        );

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('imagen', $r['datos']['error']);
        $this->assertSame(0, $this->db->countCalls('INSERT INTO links'));
    }

    public function testActualizarElAficheDeUnEvento()
    {
        $this->db->onSelect('SELECT 1 FROM links l', [[1]]);
        $this->db->onSelect('SELECT event_latitude, event_longitude FROM links', [[
            'event_latitude' => '-34.60', 'event_longitude' => '-58.38',
        ]]);
        $this->db->onWrite('UPDATE links', 1);
        $this->db->onSelect('SELECT * FROM links WHERE id', [['id' => 300]]);

        $r = $this->correr(
            'actualizar_evento',
            ['evento_id' => 300, 'imagen' => base64_encode($this->pngDeVerdad())],
            $this->discoFalso()
        );

        $this->assertTrue($r['ok'], json_encode($r['datos']));
        $this->assertStringContainsString('image_url', $this->db->callsFor('UPDATE links')[0]['sql']);
    }

    public function testSiLaImagenNuevaNoSirveNoSeActualizaNada()
    {
        $r = $this->correr(
            'actualizar_evento',
            ['evento_id' => 300, 'imagen' => base64_encode($this->pngDeVerdad())],
            $this->discoFalso(false)
        );

        $this->assertFalse($r['ok']);
        $this->assertSame(0, $this->db->countCalls('UPDATE links'));
    }

    // ============================================== link para soltar el archivo

    /**
     * El asistente sube el archivo él mismo: no puede mandarlo como argumento
     * —los argumentos son texto y una imagen no entra ahí— pero sí puede hacer
     * un POST contra el destino que se le da.
     */
    public function testDevuelveUnDestinoAlQueElAsistentePuedeSubirElArchivo()
    {
        $this->db->onSelect('SELECT 1 FROM links l', [[1]]);
        $this->db->onWrite('INSERT INTO image_uploads', 1);

        $r = $this->correr('subir_imagen', ['evento_id' => 300]);

        $this->assertTrue($r['ok']);
        $this->assertStringContainsString('/api/upload/con-token.php?token=', $r['datos']['subir_a']);
    }

    /** El comando listo evita que el modelo tenga que adivinar la forma. */
    public function testTraeElComandoArmadoConLaRutaQueLePasaron()
    {
        $this->db->onSelect('SELECT 1 FROM links l', [[1]]);
        $this->db->onWrite('INSERT INTO image_uploads', 1);

        $r = $this->correr('subir_imagen', ['evento_id' => 300, 'ruta' => '/tmp/afiche.jpg']);

        $this->assertStringContainsString('image=@/tmp/afiche.jpg', $r['datos']['comando']);
    }

    /** La ruta es sólo para armar el comando: el servidor no la lee. */
    public function testSinRutaElComandoIgualSeArma()
    {
        $this->db->onSelect('SELECT 1 FROM links l', [[1]]);
        $this->db->onWrite('INSERT INTO image_uploads', 1);

        $r = $this->correr('subir_imagen', ['evento_id' => 300]);

        $this->assertStringContainsString('curl -F', $r['datos']['comando']);
    }

    /** Queda el camino para la persona, por si el asistente no puede pedir. */
    public function testTambienDaUnLinkParaLaPersona()
    {
        $this->db->onSelect('SELECT 1 FROM links l', [[1]]);
        $this->db->onWrite('INSERT INTO image_uploads', 1);

        $r = $this->correr('subir_imagen', ['evento_id' => 300]);

        $this->assertStringContainsString('/subir/', $r['datos']['para_la_persona']);
    }

    public function testNoSeDaUnLinkParaUnEventoAjeno()
    {
        $this->db->onSelect('SELECT 1 FROM links l', []);

        $r = $this->correr('subir_imagen', ['evento_id' => 300]);

        $this->assertFalse($r['ok']);
        $this->assertSame(0, $this->db->countCalls('INSERT INTO image_uploads'));
    }

    public function testElLinkAvisaQueVence()
    {
        $this->db->onSelect('SELECT 1 FROM links l', [[1]]);
        $this->db->onWrite('INSERT INTO image_uploads', 1);

        $r = $this->correr('subir_imagen', ['evento_id' => 300]);

        $this->assertSame(\SubidasConToken::VIDA_MINUTOS, $r['datos']['vence_en_minutos']);
    }

    // ==================================== una imagen que ya está publicada

    /** Lo que el modelo sí puede escribir es una dirección. */
    public function testUnaDireccionSeReconoceComoUrlYNoComoBase64()
    {
        $this->assertTrue(HerramientasMcp::pareceUrl('https://x/afiche.jpg'));
        $this->assertTrue(HerramientasMcp::pareceUrl('  http://x/afiche.jpg'));
        $this->assertFalse(HerramientasMcp::pareceUrl(base64_encode('bytes')));
        $this->assertFalse(HerramientasMcp::pareceUrl('/Users/cris/afiche.jpg'));
        $this->assertFalse(HerramientasMcp::pareceUrl(null));
    }
}
