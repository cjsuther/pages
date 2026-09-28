<?php

namespace Tests\Unit\Lib;

use Excel;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class ExcelTest extends TestCase
{
    /** Abre el archivo y devuelve el XML de una de sus partes. */
    private function parte($contenido, $nombre)
    {
        $ruta = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($ruta, $contenido);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($ruta) === true, 'el archivo tiene que ser un zip válido');
        $xml = $zip->getFromName($nombre);
        $zip->close();
        unlink($ruta);

        $this->assertNotFalse($xml, "falta $nombre");

        return $xml;
    }

    public function testTraeLasPartesQueExcelEspera()
    {
        $archivo = Excel::tabla(['A'], [['x']]);

        foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/worksheets/sheet1.xml'] as $parte) {
            $this->assertNotEmpty($this->parte($archivo, $parte));
        }
    }

    /**
     * Que los números sean números es la razón de haber dejado el CSV: en la
     * planilla se suman sin tener que convertir nada.
     */
    public function testLosNumerosVanComoNumerosYElTextoComoTexto()
    {
        $hoja = $this->parte(Excel::tabla(['Cant', 'Nombre'], [[3, 'Ana']]), 'xl/worksheets/sheet1.xml');

        $this->assertStringContainsString('<c r="A2" s="0"><v>3</v></c>', $hoja);
        $this->assertStringContainsString('t="inlineStr"', $hoja);
    }

    public function testElEncabezadoVaEnNegrita()
    {
        $hoja = $this->parte(Excel::tabla(['Nombre'], []), 'xl/worksheets/sheet1.xml');

        $this->assertStringContainsString('<c r="A1" s="1"', $hoja);
    }

    /** Un nombre pegado de otro lado puede traer caracteres que rompen el XML. */
    public function testElTextoSeEscapaYSeLimpia()
    {
        $hoja = $this->parte(Excel::tabla(['A'], [["Ana & <Gómez>\x07"]]), 'xl/worksheets/sheet1.xml');

        $this->assertStringContainsString('Ana &amp; &lt;Gómez&gt;', $hoja);
        $this->assertStringNotContainsString("\x07", $hoja);
    }

    public function testLasColumnasSiguenElAbecedario()
    {
        $this->assertSame('A', Excel::letra(0));
        $this->assertSame('Z', Excel::letra(25));
        $this->assertSame('AA', Excel::letra(26));
        $this->assertSame('AB', Excel::letra(27));
    }

    /** Excel no abre el archivo si el nombre de la solapa tiene ciertos caracteres. */
    public function testElNombreDeLaSolapaSeLimpia()
    {
        $libro = $this->parte(Excel::tabla(['A'], [['x']], 'Ventas/2026 [enero]'), 'xl/workbook.xml');

        $this->assertStringContainsString('name="Ventas 2026  enero "', $libro);
    }

    public function testElNombreDelArchivoNoRompeLaCabecera()
    {
        $cabeceras = Excel::cabeceras('ventas del "show".xlsx');

        $this->assertStringNotContainsString('"ventas del "show"', implode('', $cabeceras));
        $this->assertSame('ventas-del-show-.xlsx', Excel::nombreSeguro('ventas del "show".xlsx'));
    }

    public function testUnLibroLlevaUnaSolapaPorHoja()
    {
        $archivo = Excel::libro([
            ['nombre' => 'Clientes', 'encabezados' => ['Email'], 'filas' => [['ana@example.com']]],
            ['nombre' => 'Compras', 'encabezados' => ['Código'], 'filas' => [['ABC123']]],
        ]);

        $libro = $this->parte($archivo, 'xl/workbook.xml');
        $this->assertStringContainsString('<sheet name="Clientes" sheetId="1" r:id="rId1"/>', $libro);
        $this->assertStringContainsString('<sheet name="Compras" sheetId="2" r:id="rId2"/>', $libro);

        $this->assertStringContainsString('ana@example.com', $this->parte($archivo, 'xl/worksheets/sheet1.xml'));
        $this->assertStringContainsString('ABC123', $this->parte($archivo, 'xl/worksheets/sheet2.xml'));

        // Los estilos van después de las hojas: si comparten id con una, Excel
        // da el archivo por dañado.
        $relaciones = $this->parte($archivo, 'xl/_rels/workbook.xml.rels');
        $this->assertStringContainsString('Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"', $relaciones);

        $tipos = $this->parte($archivo, '[Content_Types].xml');
        $this->assertStringContainsString('/xl/worksheets/sheet2.xml', $tipos);
    }
}
