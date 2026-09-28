<?php

/**
 * Genera un archivo de Excel (.xlsx).
 *
 * Reemplaza al CSV, que traía dos problemas todo el tiempo: Excel en español
 * abre las comas como una sola columna, y convierte solo lo que parece otra
 * cosa —un código que empieza con ceros pierde los ceros, una fecha cambia de
 * formato—. En un xlsx cada celda dice de qué tipo es y nada se reinterpreta.
 *
 * Un xlsx es un zip con unos pocos XML adentro, así que se arma a mano en vez
 * de sumar una dependencia grande a un hosting compartido. Lo que se necesita
 * acá son tablas con encabezado: no hay fórmulas ni estilos de celda más
 * allá del encabezado en negrita.
 */
class Excel
{
    /**
     * Devuelve el contenido del archivo.
     *
     * @param string[] $encabezados
     * @param array[]  $filas  Cada valor: número (se guarda como número),
     *                         o texto. null queda como celda vacía.
     * @param string   $hoja   Nombre de la solapa.
     * @return string
     */
    public static function tabla(array $encabezados, array $filas, $hoja = 'Hoja 1')
    {
        return self::libro([['nombre' => $hoja, 'encabezados' => $encabezados, 'filas' => $filas]]);
    }

    /**
     * Un archivo con varias solapas.
     *
     * @param array[] $hojas Cada una: nombre, encabezados y filas, como tabla().
     * @return string
     */
    public static function libro(array $hojas)
    {
        $archivos = [
            '[Content_Types].xml' => self::tiposDeContenido(count($hojas)),
            '_rels/.rels' => self::relacionesRaiz(),
            'xl/workbook.xml' => self::indice(array_column($hojas, 'nombre')),
            'xl/_rels/workbook.xml.rels' => self::relacionesDelLibro(count($hojas)),
            'xl/styles.xml' => self::estilos(),
        ];

        foreach (array_values($hojas) as $i => $hoja) {
            $archivos['xl/worksheets/sheet' . ($i + 1) . '.xml'] = self::hoja($hoja['encabezados'], $hoja['filas']);
        }

        return self::zip($archivos);
    }

    /**
     * Cabeceras HTTP para que el navegador lo baje como archivo.
     *
     * @return string[]
     */
    public static function cabeceras($nombre)
    {
        return [
            'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition: attachment; filename="' . self::nombreSeguro($nombre) . '"',
        ];
    }

    /** Un nombre de archivo que no pueda romper la cabecera. */
    public static function nombreSeguro($nombre)
    {
        $limpio = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $nombre);
        $limpio = trim($limpio, '-');

        return $limpio === '' ? 'export.xlsx' : $limpio;
    }

    // ------------------------------------------------------------ internos

    private static function hoja(array $encabezados, array $filas)
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . self::anchos(count($encabezados))
            . '<sheetData>';

        // El encabezado va congelado en negrita (estilo 1).
        $xml .= self::fila(1, $encabezados, 1);

        $numero = 2;

        foreach ($filas as $fila) {
            $xml .= self::fila($numero, array_values($fila), 0);
            $numero++;
        }

        return $xml . '</sheetData></worksheet>';
    }

    /** Un ancho parejo: sin esto las columnas salen tan angostas que todo se corta. */
    private static function anchos($columnas)
    {
        return $columnas === 0
            ? ''
            : '<cols><col min="1" max="' . $columnas . '" width="22" customWidth="1"/></cols>';
    }

    private static function fila($numero, array $valores, $estilo)
    {
        $xml = '<row r="' . $numero . '">';
        $columna = 0;

        foreach ($valores as $valor) {
            $ref = self::letra($columna) . $numero;
            $columna++;

            if ($valor === null || $valor === '') {
                continue;
            }

            if (is_int($valor) || is_float($valor)) {
                $xml .= '<c r="' . $ref . '" s="' . $estilo . '"><v>' . $valor . '</v></c>';
                continue;
            }

            // Texto en la celda (inlineStr): evita la tabla de cadenas
            // compartidas, que para una exportación no aporta nada.
            $xml .= '<c r="' . $ref . '" s="' . $estilo . '" t="inlineStr"><is><t xml:space="preserve">'
                . self::escapar((string) $valor) . '</t></is></c>';
        }

        return $xml . '</row>';
    }

    /** 0 => A, 25 => Z, 26 => AA. */
    public static function letra($indice)
    {
        $letra = '';

        for ($n = (int) $indice; $n >= 0; $n = intdiv($n, 26) - 1) {
            $letra = chr(65 + $n % 26) . $letra;
        }

        return $letra;
    }

    /**
     * Los caracteres de control rompen el XML y hacen que Excel dé el archivo
     * por corrupto. Un nombre pegado de otro lado puede traerlos.
     */
    private static function escapar($texto)
    {
        $limpio = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $texto);

        return htmlspecialchars($limpio, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function zip(array $archivos)
    {
        $ruta = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();

        if ($zip->open($ruta, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo armar el archivo de Excel');
        }

        foreach ($archivos as $nombre => $contenido) {
            $zip->addFromString($nombre, $contenido);
        }

        $zip->close();

        $contenido = file_get_contents($ruta);
        unlink($ruta);

        return $contenido;
    }

    private static function tiposDeContenido($hojas)
    {
        $partes = '';

        for ($i = 1; $i <= $hojas; $i++) {
            $partes .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . $partes
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private static function relacionesRaiz()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private static function indice(array $nombres)
    {
        $hojas = '';

        foreach (array_values($nombres) as $i => $hoja) {
            // Excel no acepta más de 31 caracteres ni : \ / ? * [ ] en el
            // nombre de una solapa: con eso adentro, no abre el archivo.
            $nombre = mb_substr(preg_replace('#[:\\\\/?*\[\]]#', ' ', (string) $hoja), 0, 31);
            $hojas .= '<sheet name="' . self::escapar($nombre) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $hojas . '</sheets>'
            . '</workbook>';
    }

    /** Una relación por hoja y, después de todas, la de los estilos. */
    private static function relacionesDelLibro($hojas)
    {
        $relaciones = '';

        for ($i = 1; $i <= $hojas; $i++) {
            $relaciones .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $relaciones
            . '<Relationship Id="rId' . ($hojas + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    /** Dos estilos: el 0 es el normal y el 1 es el encabezado en negrita. */
    private static function estilos()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            . '<borders count="1"><border/></borders>'
            . '<cellStyleXfs count="1"><xf/></cellStyleXfs>'
            . '<cellXfs count="2"><xf xfId="0"/><xf xfId="0" fontId="1" applyFont="1"/></cellXfs>'
            // Sin el estilo Normal declarado, algunos lectores avisan que el
            // libro no tiene estilo por defecto.
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }
}
