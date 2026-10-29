<?php

namespace Tests\Integration;

use CompraProductoHandler;
use LinksHandler;
use PDOException;
use PublicHandler;
use Tests\Support\FakeDisco;
use Tests\Support\FakeHttpClient;
use Tests\Support\FakeMailer;
use Tests\Support\IntegracionTestCase;

/**
 * Productos físicos contra una MariaDB de verdad (#42): el guardado de
 * características, opciones y stock por combinación, y lo que se ve en la
 * página pública.
 */
class ProductosFisicosTest extends IntegracionTestCase
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

    private function crear(array $extra = [])
    {
        return LinksHandler::index($this->db, $this->post(array_merge([
            'group_id' => $this->grupoId, 'url' => '', 'text' => 'Remera', 'precio' => '12000', 'tipo' => 'fisico',
        ], $extra), $this->user($this->duenaId)));
    }

    private function editar($linkId, array $cuerpo)
    {
        return LinksHandler::detail($this->db, $this->put($cuerpo, $this->user($this->duenaId), ['id' => $linkId]));
    }

    private function talleYColor()
    {
        return [
            'caracteristicas' => [
                ['nombre' => 'Talle', 'opciones' => ['S', 'M']],
                ['nombre' => 'Color', 'opciones' => ['Negro']],
            ],
            'variantes' => [
                ['opciones' => ['S', 'Negro'], 'stock' => 0],
                ['opciones' => ['M', 'Negro'], 'stock' => 5],
            ],
        ];
    }

    /** Un pedido (de #43) que eligió esta opción. */
    private function pedidoConOpcion($linkId, $opcionId)
    {
        $productoId = $this->valor('SELECT id FROM digital_products WHERE link_id = ?', [$linkId]);
        $ordenId = $this->insertar('product_orders', [
            'codigo' => 'ABCDEF123456', 'product_id' => $productoId, 'nombre' => 'Ana', 'email' => 'ana@test.local',
            'token_hash' => str_repeat('a', 64), 'token_cifrado' => 'x',
        ]);
        $this->insertar('product_order_options', ['order_id' => $ordenId, 'option_id' => $opcionId]);
    }

    private function opcion($nombre)
    {
        return $this->fila('SELECT * FROM product_attribute_options WHERE nombre = ?', [$nombre]);
    }

    // ============================================================ migración

    public function testLaMigracionSumaLasColumnasDelIssue()
    {
        $columnas = [];
        foreach ($this->db->query("
            SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'digital_products'
        ")->fetchAll() as $c) {
            $columnas[$c['COLUMN_NAME']] = $c;
        }

        $this->assertSame("enum('digital','fisico')", $columnas['tipo']['COLUMN_TYPE']);
        $this->assertSame("'digital'", $columnas['tipo']['COLUMN_DEFAULT']);
        $this->assertSame('decimal(12,2)', $columnas['costo_envio']['COLUMN_TYPE']);
        $this->assertSame('NO', $columnas['costo_envio']['IS_NULLABLE']);
        $this->assertSame('YES', $columnas['zona_envio']['IS_NULLABLE']);
        $this->assertSame('YES', $columnas['stock']['IS_NULLABLE']);
    }

    /** Los productos que ya existían siguen siendo digitales. */
    public function testUnProductoSinTipoEsDigital()
    {
        $linkId = $this->insertar('links', ['group_id' => $this->grupoId, 'url' => '', 'text' => 'Guía', 'position' => 0]);
        $this->insertar('digital_products', ['link_id' => $linkId]);

        $this->assertSame('digital', $this->valor('SELECT tipo FROM digital_products WHERE link_id = ?', [$linkId]));
    }

    // ================================================================ guardar

    public function testSeCreaUnFisicoSinCaracteristicas()
    {
        $res = $this->crear(['costo_envio' => '0', 'zona_envio' => 'Sólo CABA y GBA', 'stock' => '15']);

        $this->assertStatus(201, $res);
        $link = $res->body['link'];
        $this->assertSame('fisico', $link['tipo']);
        $this->assertSame('0.00', $link['costo_envio']);
        $this->assertSame('Sólo CABA y GBA', $link['zona_envio']);
        $this->assertSame(15, $link['stock']);
        $this->assertSame([], $link['caracteristicas']);
        $this->assertFalse($link['agotado']);
    }

    public function testSeCreaUnFisicoConCaracteristicasYStockPorCombinacion()
    {
        $res = $this->crear($this->talleYColor() + ['stock' => '9']);

        $this->assertStatus(201, $res);
        $link = $res->body['link'];
        $this->assertNull($link['stock']);
        $this->assertSame(['Talle', 'Color'], array_column($link['caracteristicas'], 'nombre'));
        $this->assertSame(['S', 'M'], array_column($link['caracteristicas'][0]['opciones'], 'nombre'));

        $s = $this->opcion('S')['id'];
        $m = $this->opcion('M')['id'];
        $negro = $this->opcion('Negro')['id'];
        $stock = array_column($link['variantes'], 'stock', 'clave');
        $this->assertSame(0, $stock[implode('-', [min($s, $negro), max($s, $negro)])]);
        $this->assertSame(5, $stock[implode('-', [min($m, $negro), max($m, $negro)])]);
        $this->assertFalse($link['agotado']);
    }

    public function testSeEditanRenombranYReordenanLasCaracteristicas()
    {
        $link = $this->crear($this->talleYColor())->body['link'];
        $talle = $link['caracteristicas'][0];

        $res = $this->editar($link['id'], [
            'caracteristicas' => [
                ['nombre' => 'Color', 'opciones' => ['Negro', 'Blanco']],
                ['id' => $talle['id'], 'nombre' => 'Talles', 'opciones' => [
                    ['id' => $talle['opciones'][1]['id'], 'nombre' => 'M'],
                    ['id' => $talle['opciones'][0]['id'], 'nombre' => 'Small'],
                ]],
            ],
            'variantes' => [['opciones' => ['Blanco', 'Small'], 'stock' => 2]],
        ]);

        $this->assertStatus(200, $res);
        $nuevo = $res->body['link'];
        $this->assertSame(['Color', 'Talles'], array_column($nuevo['caracteristicas'], 'nombre'));
        $this->assertSame(['M', 'Small'], array_column($nuevo['caracteristicas'][1]['opciones'], 'nombre'));
        $this->assertSame($talle['id'], $nuevo['caracteristicas'][1]['id'], 'renombrar no crea otra');
        $this->assertSame($talle['opciones'][0]['id'], $nuevo['caracteristicas'][1]['opciones'][1]['id']);
        $this->assertCount(1, $nuevo['variantes'], 'el stock por combinación se reemplaza');
        $this->assertSame(2, $nuevo['variantes'][0]['stock']);
        $this->assertSame('2', (string) $this->valor('SELECT COUNT(*) FROM product_attributes'));
    }

    /** Una opción que ya está en un pedido se desactiva y deja de ofrecerse, pero sigue en la base. */
    public function testQuitarUnaOpcionUsadaLaDesactiva()
    {
        $link = $this->crear($this->talleYColor())->body['link'];
        $s = $this->opcion('S');
        $this->pedidoConOpcion($link['id'], $s['id']);

        $res = $this->editar($link['id'], [
            'caracteristicas' => [
                ['nombre' => 'Talle', 'opciones' => ['M']],
                ['nombre' => 'Color', 'opciones' => ['Negro']],
            ],
        ]);

        $this->assertStatus(200, $res);
        $this->assertSame('0', (string) $this->opcion('S')['activa']);
        $this->assertSame(['M'], array_column($res->body['link']['caracteristicas'][0]['opciones'], 'nombre'));

        // Volver a agregarla la reactiva: es la misma opción, no otra.
        $res = $this->editar($link['id'], [
            'caracteristicas' => [['nombre' => 'Talle', 'opciones' => ['S', 'M']], ['nombre' => 'Color', 'opciones' => ['Negro']]],
        ]);
        $this->assertSame($s['id'], $res->body['link']['caracteristicas'][0]['opciones'][0]['id']);
        $this->assertSame('1', (string) $this->opcion('S')['activa']);
    }

    public function testQuitarUnaCaracteristicaConPedidosLaDesactivaYSinPedidosLaBorra()
    {
        $link = $this->crear($this->talleYColor())->body['link'];
        $this->pedidoConOpcion($link['id'], $this->opcion('Negro')['id']);

        $res = $this->editar($link['id'], ['caracteristicas' => [], 'stock' => '3']);

        $this->assertStatus(200, $res);
        $this->assertSame([], $res->body['link']['caracteristicas']);
        $this->assertSame(3, $res->body['link']['stock']);
        $this->assertSame('0', (string) $this->valor('SELECT activa FROM product_attributes WHERE nombre = ?', ['Color']));
        $this->assertFalse($this->valor('SELECT id FROM product_attributes WHERE nombre = ?', ['Talle']), 'Talle no tenía pedidos');
        $this->assertSame('0', (string) $this->valor('SELECT COUNT(*) FROM product_variants'));
    }

    /** Aunque la API se equivocara, la base no deja borrar una opción usada. */
    public function testLaBaseNoDejaBorrarUnaOpcionUsada()
    {
        $link = $this->crear($this->talleYColor())->body['link'];
        $s = $this->opcion('S');
        $this->pedidoConOpcion($link['id'], $s['id']);

        $this->expectException(PDOException::class);
        $this->db->prepare('DELETE FROM product_attribute_options WHERE id = ?')->execute([$s['id']]);
    }

    public function testUnErrorNoDejaLasCaracteristicasAMedias()
    {
        $link = $this->crear($this->talleYColor())->body['link'];

        $res = $this->editar($link['id'], [
            'caracteristicas' => [['nombre' => 'Talle', 'opciones' => ['S', str_repeat('x', 51)]]],
        ]);

        $this->assertStatus(400, $res);
        $this->assertSame('3', (string) $this->valor('SELECT COUNT(*) FROM product_attribute_options'));
    }

    public function testBorrarUnFisicoSinPedidosSeLlevaSusCaracteristicas()
    {
        $link = $this->crear($this->talleYColor())->body['link'];

        $res = LinksHandler::detail($this->db, $this->delete(['id' => $link['id']], $this->user($this->duenaId)), new FakeDisco());

        $this->assertStatus(200, $res);
        $this->assertSame('0', (string) $this->valor('SELECT COUNT(*) FROM product_attributes'));
        $this->assertSame('0', (string) $this->valor('SELECT COUNT(*) FROM product_attribute_options'));
        $this->assertSame('0', (string) $this->valor('SELECT COUNT(*) FROM product_variants'));
    }

    // ============================================================== público

    public function testUnFisicoSinArchivoSeVeEnLaPaginaYEnSuLink()
    {
        $link = $this->crear($this->talleYColor())->body['link'];

        $pagina = PublicHandler::page($this->db, $this->get(['slug' => 'la-sala']));
        $producto = $pagina->body['page']['groups'][0]['links'][0];
        $this->assertSame('fisico', $producto['tipo']);
        $this->assertCount(1, $producto['agotadas']);
        $this->assertArrayNotHasKey('stock', $producto);

        $res = PublicHandler::producto($this->db, $this->get(['id' => $link['id']]));
        $this->assertStatus(200, $res);
        $this->assertSame(['Talle', 'Color'], array_column($res->body['producto']['caracteristicas'], 'nombre'));
    }

    public function testUnFisicoInactivoNoSeVe()
    {
        $link = $this->crear()->body['link'];
        $this->editar($link['id'], ['oculto' => true]);

        $pagina = PublicHandler::page($this->db, $this->get(['slug' => 'la-sala']));
        $this->assertSame([], $pagina->body['page']['groups'][0]['links']);
        $this->assertStatus(404, PublicHandler::producto($this->db, $this->get(['id' => $link['id']])));
    }

    public function testTodoAgotado()
    {
        $res = $this->crear(['stock' => '0']);

        $this->assertTrue($res->body['link']['agotado']);
    }

    /** Hasta la compra con envío (#43), un físico no acepta pedidos. */
    public function testUnFisicoTodaviaNoSeVende()
    {
        $link = $this->crear(['precio' => '0'])->body['link'];

        $res = CompraProductoHandler::comprar($this->db, $this->post([
            'link_id' => $link['id'], 'nombre' => 'Ana Compradora', 'email' => 'ana@test.local',
        ]), new FakeHttpClient(), new FakeMailer());

        $this->assertStatus(404, $res);
        $this->assertSame('0', (string) $this->valor('SELECT COUNT(*) FROM product_orders'));
    }
}
