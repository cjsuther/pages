<?php

namespace Tests\Integration;

use Tests\Support\Esquema;
use Tests\Support\IntegracionTestCase;

/**
 * El esquema que arman el repositorio y el que deploy.sh le exige a
 * producción tienen que ser el mismo.
 *
 * La falla que motivó esto: se subió código que usaba event_records y la
 * migración que la crea nunca se había corrido en producción. Durante 16 horas
 * no se pudo reservar. deploy.sh ahora compara tests/esquema-esperado.json con
 * la base de producción y no sube si falta algo; estos tests se aseguran de que
 * ese archivo diga la verdad.
 */
class EsquemaTest extends IntegracionTestCase
{
    /** Una migración fuera de la lista no se aplica en los tests ni se le exige a producción. */
    public function testTodaMigracionEstaEnElOrden()
    {
        $sinListar = array_diff(Esquema::migracionesEnDisco(), Esquema::orden());

        $this->assertSame([], array_values($sinListar),
            'Estas migraciones no están en migraciones.txt; agregalas al final: ' . implode(', ', $sinListar));
    }

    public function testElOrdenNoNombraArchivosQueNoExisten()
    {
        $inexistentes = array_filter(Esquema::orden(), function ($archivo) {
            return !is_file(Esquema::raiz() . '/' . $archivo);
        });

        $this->assertSame([], array_values($inexistentes));
    }

    public function testElOrdenEmpiezaPorElEsquemaBase()
    {
        $this->assertSame('database.sql', Esquema::orden()[0]);
    }

    /**
     * Si esto falla, alguien agregó o cambió una migración y no regeneró el
     * archivo. Correr: php tests/esquema.php generar
     */
    public function testElEsquemaEsperadoEstaAlDia()
    {
        $guardado = json_decode(file_get_contents(__DIR__ . '/../esquema-esperado.json'), true);

        $this->assertEquals(self::esquema(), $guardado,
            'tests/esquema-esperado.json no coincide con lo que arman las migraciones. Correr: php tests/esquema.php generar');
    }

    /** Las tablas de las que depende la venta de entradas, con la migración que las crea. */
    public function testLaVentaTieneSusTablas()
    {
        $esquema = self::esquema();

        foreach (['event_ticketing', 'ticket_orders', 'ticket_order_lugares', 'event_records', 'event_record_pages', 'page_payment_settings'] as $tabla) {
            $this->assertArrayHasKey($tabla, $esquema, "falta la tabla $tabla");
        }

        $this->assertSame('migration_clientes.sql', $esquema['event_records']['id']);
        $this->assertSame('migration_clientes.sql', $esquema['ticket_orders']['record_id']);
    }

    public function testLosGruposTienenTipo()
    {
        $this->assertArrayHasKey('type', self::esquema()['link_groups']);
    }

    /** Lo que deploy.sh hubiera dicho el 29/9, con la base de producción de ese día. */
    public function testSinLaMigracionDeClientesElDeployLaNombra()
    {
        $real = [];
        foreach (self::esquema() as $tabla => $columnas) {
            $real[$tabla] = array_keys($columnas);
        }
        unset($real['event_records'], $real['event_record_pages']);
        $real['ticket_orders'] = array_values(array_diff($real['ticket_orders'], ['record_id']));

        $faltan = Esquema::faltantes(self::esquema(), $real);

        $this->assertSame(['migration_clientes.sql'], array_keys($faltan));
        $this->assertContains('tabla event_records', $faltan['migration_clientes.sql']);
        $this->assertContains('columna ticket_orders.record_id', $faltan['migration_clientes.sql']);
    }

    public function testConLaBaseAlDiaNoFaltaNada()
    {
        $real = [];
        foreach (self::esquema() as $tabla => $columnas) {
            $real[$tabla] = array_keys($columnas);
        }

        $this->assertSame([], Esquema::faltantes(self::esquema(), $real));
    }
}
