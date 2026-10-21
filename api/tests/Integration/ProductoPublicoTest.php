<?php

namespace Tests\Integration;

use LinksHandler;
use ProductosDigitales;
use PublicHandler;
use Tests\Support\FakeDisco;
use Tests\Support\IntegracionTestCase;

/**
 * /producto/:id contra una MariaDB de verdad (#27): el link propio de un
 * producto sólo lo muestra mientras está en venta, y nunca deja ver la ruta
 * del archivo ni nada de las ventas.
 */
class ProductoPublicoTest extends IntegracionTestCase
{
    private $duenaId;
    private $paginaId;
    private $grupoId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->duenaId = $this->usuario();
        $this->paginaId = $this->pagina($this->duenaId);
        $this->grupoId = $this->grupo($this->paginaId, 'productos', 'Tienda');
    }

    private function producto(array $datos = [])
    {
        $linkId = $this->insertar('links', [
            'group_id' => $this->grupoId, 'url' => '', 'text' => 'Guía de viaje', 'position' => 0,
            'description' => 'Todo lo que hay que saber', 'image_url' => 'https://rezonar.test/api/uploads/tapa.jpg',
        ]);
        $this->insertar('digital_products', array_merge([
            'link_id' => $linkId, 'titulo' => 'Guía de viaje', 'page_id' => $this->paginaId,
            'precio' => '2500.00', 'archivo_ruta' => ProductosDigitales::rutaNueva(),
            'archivo_nombre' => 'guía.pdf', 'archivo_bytes' => 2048, 'archivo_tipo' => 'application/pdf',
        ], $datos));

        return $linkId;
    }

    private function pedir($id)
    {
        return PublicHandler::producto($this->db, $this->get(['id' => (string) $id]));
    }

    private function assertNoDisponible($res)
    {
        $this->assertStatus(404, $res);
        $this->assertSame(['error' => ProductosDigitales::MENSAJE_NO_DISPONIBLE], $res->body);
    }

    public function testUnProductoEnVentaSaleConSuPagina()
    {
        $id = $this->producto();

        $res = $this->pedir($id);

        $this->assertStatus(200, $res);
        $producto = $res->body['producto'];
        $this->assertSame((int) $id, $producto['id']);
        $this->assertSame('Guía de viaje', $producto['text']);
        $this->assertSame('Todo lo que hay que saber', $producto['description']);
        $this->assertSame(2500.0, $producto['precio']);
        $this->assertSame('guía.pdf', $producto['archivo_nombre']);
        $this->assertSame(2048, $producto['archivo_bytes']);
        $this->assertSame('La Sala', $producto['page_title']);
        $this->assertSame('la-sala', $producto['page_slug']);
        $this->assertArrayHasKey('background_color', $producto);
    }

    public function testLaRespuestaNoLlevaLaRutaNiLasVentas()
    {
        $id = $this->producto();
        $ruta = $this->valor('SELECT archivo_ruta FROM digital_products WHERE link_id = ?', [$id]);

        $json = json_encode($this->pedir($id)->body);

        $this->assertStringNotContainsString('archivo_ruta', $json);
        $this->assertStringNotContainsString(substr($ruta, strlen('productos/')), $json);
        $this->assertStringNotContainsString('oculto', $json);
        $this->assertStringNotContainsString('borrado_en', $json);
    }

    public function testUnProductoGratisTambienSale()
    {
        $res = $this->pedir($this->producto(['precio' => '0.00']));

        $this->assertStatus(200, $res);
        $this->assertSame(0.0, $res->body['producto']['precio']);
    }

    public function testUnProductoOcultoDa404()
    {
        $id = $this->producto();
        $this->assertStatus(200, LinksHandler::detail($this->db, $this->put(['oculto' => true], $this->user($this->duenaId), ['id' => $id])));

        $this->assertNoDisponible($this->pedir($id));
    }

    public function testUnProductoSinArchivoDa404()
    {
        $this->assertNoDisponible($this->pedir($this->producto([
            'archivo_ruta' => null, 'archivo_nombre' => null, 'archivo_bytes' => null, 'archivo_tipo' => null,
        ])));
    }

    public function testUnProductoBorradoDa404()
    {
        $id = $this->producto();
        $res = LinksHandler::detail($this->db, $this->delete(['id' => $id], $this->user($this->duenaId)), new FakeDisco());
        $this->assertStatus(200, $res);

        $this->assertNoDisponible($this->pedir($id));
    }

    /** Marcado como borrado con el link todavía en pie: tampoco se muestra. */
    public function testUnProductoMarcadoComoBorradoDa404()
    {
        $id = $this->producto(['borrado_en' => '2026-01-01 00:00:00']);

        $this->assertNoDisponible($this->pedir($id));
    }

    public function testUnIdQueNoExisteDa404()
    {
        $this->assertNoDisponible($this->pedir(999999));
    }

    /** Un evento tiene id de link como un producto, pero no es uno. */
    public function testUnEventoNoSaleComoProducto()
    {
        $eventos = $this->grupo($this->paginaId, 'eventos', 'Fechas');

        $this->assertNoDisponible($this->pedir($this->evento($eventos)));
    }
}
