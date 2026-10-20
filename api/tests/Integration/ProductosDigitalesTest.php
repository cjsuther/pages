<?php

namespace Tests\Integration;

use GroupsHandler;
use LinksHandler;
use PagesHandler;
use PDOException;
use ProductosDigitales;
use ProductosHandler;
use PublicHandler;
use Request;
use Tests\Support\FakeDisco;
use Tests\Support\IntegracionTestCase;

/**
 * Productos digitales contra una MariaDB de verdad (#17).
 *
 * El disco es un FakeDisco: lo que se mira acá son las filas, y que lo que se
 * borra de la base se lleve su archivo. Que la carpeta quede protegida lo
 * cubren los tests unitarios de ProductosDigitales.
 */
class ProductosDigitalesTest extends IntegracionTestCase
{
    private $duenaId;
    private $paginaId;
    private $grupoId;
    /** @var FakeDisco */
    private $disco;

    protected function setUp(): void
    {
        parent::setUp();

        $this->duenaId = $this->usuario();
        $this->paginaId = $this->pagina($this->duenaId);
        $this->disco = new FakeDisco();
    }

    // ============================================================ migración

    public function testElTipoProductosExisteEnLaBase()
    {
        $tipo = $this->fila("
            SELECT COLUMN_TYPE FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'link_groups' AND COLUMN_NAME = 'type'
        ")['COLUMN_TYPE'];

        $this->assertSame("enum('links','galeria','eventos','productos')", $tipo);
    }

    public function testLaTablaDeProductosTieneLoQuePideElIssue()
    {
        $columnas = [];
        $stmt = $this->db->query("
            SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'digital_products'
        ");
        foreach ($stmt->fetchAll() as $c) {
            $columnas[$c['COLUMN_NAME']] = $c;
        }

        foreach (['link_id', 'precio', 'archivo_ruta', 'archivo_nombre', 'archivo_bytes', 'archivo_tipo', 'creado_en', 'actualizado_en'] as $columna) {
            $this->assertArrayHasKey($columna, $columnas, $columna);
        }
        $this->assertSame('decimal(12,2)', $columnas['precio']['COLUMN_TYPE']);
        $this->assertSame('NO', $columnas['precio']['IS_NULLABLE']);
    }

    /** Un link no puede tener dos productos. */
    public function testUnLinkTieneUnSoloProducto()
    {
        $linkId = $this->producto();

        $this->expectException(PDOException::class);
        $this->insertar('digital_products', ['link_id' => $linkId]);
    }

    /**
     * Desde #18 la base ya no borra el producto con el link: le deja link_id
     * en null, para que sus ventas sobrevivan. Quien borra la fila de un
     * producto sin órdenes es la API (ProductosDigitales::soltar).
     */
    public function testBorrarElLinkDejaLaFilaDelProductoSinLink()
    {
        $linkId = $this->producto();

        $this->db->prepare('DELETE FROM links WHERE id = ?')->execute([$linkId]);

        $this->assertSame(1, (int) $this->valor('SELECT COUNT(*) FROM digital_products WHERE link_id IS NULL'));
    }

    /** La migración se puede volver a correr sin romper nada. */
    public function testLaMigracionEsIdempotente()
    {
        $linkId = $this->producto(['precio' => '700']);
        $sql = file_get_contents(dirname(__DIR__, 3) . '/migration_productos_digitales.sql');

        foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', $sql)))) as $sentencia) {
            $this->db->exec($sentencia);
        }

        $this->assertSame('700.00', $this->valor('SELECT precio FROM digital_products WHERE link_id = ?', [$linkId]));
    }

    // ================================================================= CRUD

    public function testSeCreaUnGrupoDeProductos()
    {
        $res = GroupsHandler::index($this->db, $this->post(
            ['page_id' => $this->paginaId, 'title' => 'Tienda', 'type' => 'productos'],
            $this->user($this->duenaId)
        ));

        $this->assertStatus(201, $res);
        $this->assertSame('productos', $res->body['group']['type']);
    }

    public function testSeCreaUnProductoConSuPrecio()
    {
        $res = $this->crear(['precio' => '2500.50']);

        $this->assertStatus(201, $res);
        $link = $res->body['link'];
        $this->assertSame('Guía de viaje', $link['text']);
        $this->assertSame('', $link['url']);
        $this->assertSame('2500.50', $link['precio']);
        $this->assertNull($link['archivo_nombre']);
        $this->assertArrayNotHasKey('archivo_ruta', $link);
    }

    public function testSeEditanElTituloYElPrecio()
    {
        $linkId = $this->crear()->body['link']['id'];

        $res = LinksHandler::detail($this->db, $this->put(
            ['text' => 'Guía 2026', 'description' => 'Actualizada', 'precio' => '0'],
            $this->user($this->duenaId),
            ['id' => $linkId]
        ));

        $this->assertStatus(200, $res);
        $this->assertSame('Guía 2026', $res->body['link']['text']);
        $this->assertSame('0.00', $res->body['link']['precio']);
    }

    public function testSeSubeElArchivo()
    {
        $linkId = $this->crear()->body['link']['id'];

        $res = $this->subir($linkId, 'guia.pdf', 4096);

        $this->assertStatus(200, $res);
        $this->assertSame('guia.pdf', $res->body['link']['archivo_nombre']);
        $this->assertSame('4096', (string) $res->body['link']['archivo_bytes']);
        $this->assertSame('application/pdf', $res->body['link']['archivo_tipo']);

        $ruta = $this->valor('SELECT archivo_ruta FROM digital_products WHERE link_id = ?', [$linkId]);
        $this->assertTrue(ProductosDigitales::rutaValida($ruta));
        $this->assertArrayHasKey(ProductosDigitales::rutaEnDisco($ruta), $this->disco->archivos);
    }

    public function testReemplazarElArchivoBorraElAnterior()
    {
        $linkId = $this->crear()->body['link']['id'];
        $this->subir($linkId, 'v1.pdf');
        $primera = $this->valor('SELECT archivo_ruta FROM digital_products WHERE link_id = ?', [$linkId]);

        $res = $this->subir($linkId, 'v2.pdf');

        $this->assertSame('v2.pdf', $res->body['link']['archivo_nombre']);
        $segunda = $this->valor('SELECT archivo_ruta FROM digital_products WHERE link_id = ?', [$linkId]);
        $this->assertNotSame($primera, $segunda);
        $this->assertSame([ProductosDigitales::rutaEnDisco($primera)], $this->disco->borrados);
    }

    /** Editar el precio después de subir el archivo no lo pierde. */
    public function testCambiarElPrecioConservaElArchivo()
    {
        $linkId = $this->crear()->body['link']['id'];
        $this->subir($linkId, 'guia.pdf');

        $res = LinksHandler::detail($this->db, $this->put(['precio' => 999], $this->user($this->duenaId), ['id' => $linkId]));

        $this->assertSame('999.00', $res->body['link']['precio']);
        $this->assertSame('guia.pdf', $res->body['link']['archivo_nombre']);
    }

    public function testBorrarElProductoBorraSuArchivo()
    {
        $linkId = $this->crear()->body['link']['id'];
        $this->subir($linkId, 'guia.pdf');
        $ruta = $this->valor('SELECT archivo_ruta FROM digital_products WHERE link_id = ?', [$linkId]);

        $res = LinksHandler::detail($this->db, $this->delete(['id' => $linkId], $this->user($this->duenaId)), $this->disco);

        $this->assertStatus(200, $res);
        $this->assertSame(0, (int) $this->valor('SELECT COUNT(*) FROM digital_products'));
        $this->assertSame([ProductosDigitales::rutaEnDisco($ruta)], $this->disco->borrados);
    }

    public function testBorrarElGrupoBorraLosArchivosDeSusProductos()
    {
        $a = $this->crear()->body['link']['id'];
        $b = $this->crear(['text' => 'Otro'])->body['link']['id'];
        $this->subir($a, 'a.pdf');
        $this->subir($b, 'b.pdf');
        $rutas = $this->db->query('SELECT archivo_ruta FROM digital_products ORDER BY link_id')->fetchAll(\PDO::FETCH_COLUMN);

        $res = GroupsHandler::detail($this->db, $this->delete(['id' => $this->grupoId], $this->user($this->duenaId)), $this->disco);

        $this->assertStatus(200, $res);
        $this->assertSame(0, (int) $this->valor('SELECT COUNT(*) FROM digital_products'));
        $this->assertEqualsCanonicalizing(array_map([ProductosDigitales::class, 'rutaEnDisco'], $rutas), $this->disco->borrados);
    }

    public function testBorrarLaPaginaBorraLosArchivosDeSusProductos()
    {
        $linkId = $this->crear()->body['link']['id'];
        $this->subir($linkId, 'guia.pdf');
        $ruta = $this->valor('SELECT archivo_ruta FROM digital_products WHERE link_id = ?', [$linkId]);

        $res = PagesHandler::detail($this->db, $this->delete(['id' => $this->paginaId], $this->user($this->duenaId)), $this->disco);

        $this->assertStatus(200, $res);
        $this->assertSame([ProductosDigitales::rutaEnDisco($ruta)], $this->disco->borrados);
    }

    public function testElEditorVeLosProductosConSuArchivo()
    {
        $linkId = $this->crear(['precio' => 1500])->body['link']['id'];
        $this->subir($linkId, 'guia.pdf', 2048);

        $res = PagesHandler::detail($this->db, $this->get(['id' => $this->paginaId], $this->user($this->duenaId)));

        $producto = $res->body['page']['groups'][0]['links'][0];
        $this->assertSame('1500.00', $producto['precio']);
        $this->assertSame('guia.pdf', $producto['archivo_nombre']);
        $this->assertArrayNotHasKey('archivo_ruta', $producto);
    }

    public function testOtraPersonaNoPuedeSubirleUnArchivo()
    {
        $linkId = $this->crear()->body['link']['id'];
        $otra = $this->usuario('otra@test.local', 'Otra');

        $req = new Request('POST', [], ['link_id' => $linkId], $this->user($otra, 'otra@test.local'), [
            'archivo' => ['name' => 'x.pdf', 'size' => 10, 'tmp_name' => '/tmp/x', 'error' => UPLOAD_ERR_OK],
        ]);

        $this->assertStatus(404, ProductosHandler::archivo($this->db, $req, $this->disco));
        $this->assertSame([], $this->disco->movidos);
    }

    /** Todavía no se vende: el grupo no tiene que aparecer en la página pública. */
    public function testLaPaginaPublicaNoMuestraLosProductos()
    {
        $this->crear();
        $linksId = $this->grupo($this->paginaId, 'links', 'Mis links');
        $this->insertar('links', ['group_id' => $linksId, 'url' => 'https://ejemplo.com', 'text' => 'Un link', 'position' => 0]);

        $res = PublicHandler::page($this->db, $this->get(['slug' => 'la-sala']));

        $this->assertStatus(200, $res);
        $this->assertSame(['Mis links'], array_column($res->body['page']['groups'], 'title'));
        $this->assertStringNotContainsString('Guía de viaje', json_encode($res->body));
    }

    // ============================================================ ayudantes

    /** Crea un producto por la API, en el grupo de productos de la página. */
    private function crear(array $datos = [])
    {
        if ($this->grupoId === null) {
            $this->grupoId = $this->grupo($this->paginaId, 'productos', 'Tienda');
        }

        return LinksHandler::index($this->db, $this->post(array_merge([
            'group_id' => $this->grupoId,
            'url' => '',
            'text' => 'Guía de viaje',
            'description' => 'Todo lo que hay que saber',
            'image_url' => 'https://rezonar.test/api/uploads/tapa.jpg',
        ], $datos), $this->user($this->duenaId)));
    }

    /** Un producto cargado directo en la base. */
    private function producto(array $datos = [])
    {
        $grupo = $this->grupo($this->paginaId, 'productos', 'Tienda');
        $linkId = $this->insertar('links', ['group_id' => $grupo, 'url' => '', 'text' => 'Guía', 'position' => 0]);
        $this->insertar('digital_products', array_merge(['link_id' => $linkId], $datos));

        return $linkId;
    }

    private function subir($linkId, $nombre, $bytes = 1024)
    {
        $req = new Request('POST', [], ['link_id' => $linkId], $this->user($this->duenaId), [
            'archivo' => ['name' => $nombre, 'type' => 'application/pdf', 'size' => $bytes, 'tmp_name' => '/tmp/' . $nombre, 'error' => UPLOAD_ERR_OK],
        ]);

        return ProductosHandler::archivo($this->db, $req, $this->disco);
    }
}
