<?php

namespace Tests\Unit\Lib;

use ImagenesHuerfanas;
use Tests\Support\HandlerTestCase;

class ImagenesHuerfanasTest extends HandlerTestCase
{
    /** Lo que devuelve cada consulta de referencias, en el orden en que se piden. */
    private function seUsan(array $porTabla = [])
    {
        foreach (ImagenesHuerfanas::REFERENCIAS as $referencia) {
            list($tabla, $columna) = $referencia;
            $valores = isset($porTabla["$tabla.$columna"]) ? $porTabla["$tabla.$columna"] : [];

            $this->db->onSelect("SELECT $columna FROM $tabla", array_map(function ($v) {
                return [$v];
            }, $valores));
        }
    }

    private function enDisco(array $archivos)
    {
        return function () use ($archivos) {
            return $archivos;
        };
    }

    /** Un archivo viejo, fuera de la ventana de gracia. */
    private function viejo()
    {
        return time() - (ImagenesHuerfanas::HORAS_DE_GRACIA + 24) * 3600;
    }

    // ------------------------------------------------------ nombre de archivo

    /**
     * La dirección con la que se guardó una imagen depende de UPLOAD_URL, que
     * ya cambió una vez. Comparar URLs enteras daría "no la usa nadie" para
     * media biblioteca.
     */
    public function testElNombreSaleDeLaUrlSinImportarElDominio()
    {
        $this->assertSame('foto.jpg', ImagenesHuerfanas::nombreDeArchivo('https://rezon.ar/api/uploads/foto.jpg'));
        $this->assertSame('foto.jpg', ImagenesHuerfanas::nombreDeArchivo('http://localhost:8000/uploads/foto.jpg'));
        $this->assertSame('foto.jpg', ImagenesHuerfanas::nombreDeArchivo('/uploads/foto.jpg'));
    }

    /** La misma imagen puede estar referida con y sin parámetros de caché. */
    public function testElQueryStringNoCambiaElNombre()
    {
        $this->assertSame('foto.jpg', ImagenesHuerfanas::nombreDeArchivo('https://rezon.ar/uploads/foto.jpg?v=3'));
    }

    public function testLoQueNoTieneFormaDeArchivoSeIgnora()
    {
        $this->assertNull(ImagenesHuerfanas::nombreDeArchivo(''));
        $this->assertNull(ImagenesHuerfanas::nombreDeArchivo('   '));
        $this->assertNull(ImagenesHuerfanas::nombreDeArchivo(null));
    }

    // ------------------------------------------------------------- en uso

    public function testJuntaLasReferenciasDeTodasLasTablas()
    {
        $this->seUsan([
            'links.image_url' => ['https://rezon.ar/api/uploads/evento.jpg'],
            'pages.profile_image' => ['https://rezon.ar/api/uploads/perfil.png'],
        ]);

        $usadas = ImagenesHuerfanas::enUso($this->db);

        $this->assertArrayHasKey('evento.jpg', $usadas);
        $this->assertArrayHasKey('perfil.png', $usadas);
    }

    /** Si una columna nueva no se agrega acá, se borran imágenes en uso. */
    public function testSeMiranTodasLasColumnasConocidas()
    {
        $this->seUsan();

        ImagenesHuerfanas::enUso($this->db);

        foreach (ImagenesHuerfanas::REFERENCIAS as $referencia) {
            list($tabla, $columna) = $referencia;

            $this->assertSame(1, $this->db->countCalls("SELECT $columna FROM $tabla"), "$tabla.$columna");
        }
    }

    // ---------------------------------------------------------- huérfanas

    public function testUnaImagenQueNadieUsaSeBorra()
    {
        $this->seUsan();

        $r = ImagenesHuerfanas::huerfanas($this->db, $this->enDisco(['sobrante.jpg' => $this->viejo()]));

        $this->assertSame(['sobrante.jpg'], $r);
    }

    public function testUnaImagenEnUsoNoSeBorra()
    {
        $this->seUsan(['links.image_url' => ['https://rezon.ar/api/uploads/usada.jpg']]);

        $r = ImagenesHuerfanas::huerfanas($this->db, $this->enDisco(['usada.jpg' => $this->viejo()]));

        $this->assertSame([], $r);
    }

    /**
     * Entre subir la imagen y guardar la fila que la menciona pasan segundos,
     * o los minutos que alguien tarde en apretar Guardar. En esa ventana el
     * archivo existe sin que nada lo referencie todavía.
     */
    public function testUnaImagenRecienSubidaNoSeBorraAunqueNadieLaUse()
    {
        $this->seUsan();

        $r = ImagenesHuerfanas::huerfanas($this->db, $this->enDisco(['recien.jpg' => time() - 60]));

        $this->assertSame([], $r);
    }

    public function testLaVentanaDeGraciaTerminaCuandoTieneQueTerminar()
    {
        $this->seUsan();
        $ahora = 1787000000;

        $justoAdentro = $ahora - (ImagenesHuerfanas::HORAS_DE_GRACIA * 3600) + 60;
        $justoAfuera = $ahora - (ImagenesHuerfanas::HORAS_DE_GRACIA * 3600) - 60;

        $r = ImagenesHuerfanas::huerfanas($this->db, $this->enDisco([
            'nueva.jpg' => $justoAdentro,
            'vieja.jpg' => $justoAfuera,
        ]), $ahora);

        $this->assertSame(['vieja.jpg'], $r);
    }

    /** Borrar el .htaccess dejaría a Apache ejecutando PHP en uploads/. */
    public function testLosArchivosOcultosNoSeTocan()
    {
        $this->seUsan();

        $r = ImagenesHuerfanas::huerfanas($this->db, $this->enDisco([
            '.htaccess' => $this->viejo(),
            '.gitkeep' => $this->viejo(),
        ]));

        $this->assertSame([], $r);
    }

    /** El perfil y el fondo de una página cuentan igual que un evento. */
    public function testUnaImagenUsadaSoloComoFondoDePaginaNoSeBorra()
    {
        $this->seUsan(['pages.background_image' => ['https://rezon.ar/api/uploads/fondo.jpg']]);

        $r = ImagenesHuerfanas::huerfanas($this->db, $this->enDisco(['fondo.jpg' => $this->viejo()]));

        $this->assertSame([], $r);
    }

    public function testUnDirectorioVacioNoRompe()
    {
        $this->seUsan();

        $this->assertSame([], ImagenesHuerfanas::huerfanas($this->db, $this->enDisco([])));
    }

    // ---------------------------------------------------- uploads/privado/

    /**
     * Los archivos de los productos digitales no los referencia ninguna de
     * las columnas de imágenes: para esta cuenta estarían todos "sin usar".
     * El listado del disco no tiene que entrar nunca a uploads/privado/.
     */
    public function testElListadoNoEntraALaCarpetaPrivada()
    {
        $uploads = $this->uploadsDePrueba();

        $listado = ImagenesHuerfanas::listar($uploads);

        $this->assertSame(['afiche.jpg'], array_keys($listado));

        $this->borrarUploadsDePrueba($uploads);
    }

    /** De punta a punta: un producto viejo y sin referencias no se borra. */
    public function testUnProductoDigitalViejoNoSeBorra()
    {
        $this->seUsan();
        $uploads = $this->uploadsDePrueba();
        $listar = function () use ($uploads) {
            return ImagenesHuerfanas::listar($uploads);
        };

        $r = ImagenesHuerfanas::huerfanas($this->db, $listar, time() + 365 * 86400);

        $this->assertSame(['afiche.jpg'], $r);

        $this->borrarUploadsDePrueba($uploads);
    }

    /** Aunque alguien cambie el listado, la carpeta y lo de adentro quedan afuera. */
    public function testLaCarpetaPrivadaNoSeConsideraNuncaUnaImagen()
    {
        $this->seUsan();

        $r = ImagenesHuerfanas::huerfanas($this->db, $this->enDisco([
            'privado' => $this->viejo(),
            'privado/productos/' . str_repeat('a', 32) => $this->viejo(),
        ]));

        $this->assertSame([], $r);
    }

    /** uploads/ con una imagen suelta y un producto en privado/productos/. */
    private function uploadsDePrueba()
    {
        $uploads = sys_get_temp_dir() . '/rezonar-uploads-' . uniqid();
        mkdir($uploads . '/privado/productos', 0755, true);
        file_put_contents($uploads . '/afiche.jpg', 'x');
        file_put_contents($uploads . '/privado/.htaccess', 'Require all denied');
        file_put_contents($uploads . '/privado/productos/' . str_repeat('a', 32), 'pdf');

        foreach (['/afiche.jpg', '/privado/productos/' . str_repeat('a', 32)] as $archivo) {
            touch($uploads . $archivo, $this->viejo());
        }

        return $uploads;
    }

    private function borrarUploadsDePrueba($uploads)
    {
        unlink($uploads . '/privado/productos/' . str_repeat('a', 32));
        unlink($uploads . '/privado/.htaccess');
        unlink($uploads . '/afiche.jpg');
        rmdir($uploads . '/privado/productos');
        rmdir($uploads . '/privado');
        rmdir($uploads);
    }
}
