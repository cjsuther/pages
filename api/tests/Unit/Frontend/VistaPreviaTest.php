<?php

namespace Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * La vista previa que arma frontend/public/index.php al compartir un link
 * (WhatsApp, redes): los meta tags og:*.
 *
 * index.php le pide los datos a la API por HTTP, así que acá se levanta una
 * API falsa con `php -S` (tests/Fixtures/vista-previa/api-falsa.php) y se
 * corre index.php contra ella en otro proceso.
 */
class VistaPreviaTest extends TestCase
{
    /** @var resource|null */
    private static $servidor;
    private static $url;
    private static $log;

    public static function setUpBeforeClass(): void
    {
        if (!function_exists('proc_open')) {
            return;
        }

        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $direccion = stream_socket_get_name($socket, false);
        fclose($socket);

        // Sin opcache: no hace falta, y en algunos entornos no puede crear su
        // archivo de bloqueo en /tmp y el servidor no arranca.
        $comando = [PHP_BINARY, '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0', '-S', $direccion, dirname(__DIR__, 2) . '/Fixtures/vista-previa/api-falsa.php'];
        self::$log = tempnam(sys_get_temp_dir(), 'api-falsa');
        $log = ['file', self::$log, 'w'];
        self::$servidor = proc_open($comando, [0 => ['file', '/dev/null', 'r'], 1 => $log, 2 => $log], $pipes);

        for ($i = 0; $i < 100; $i++) {
            $conexion = @fsockopen('127.0.0.1', (int) substr(strrchr($direccion, ':'), 1), $errno, $errstr, 0.1);
            if ($conexion) {
                fclose($conexion);
                self::$url = 'http://' . $direccion;
                return;
            }
            usleep(50000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$servidor) {
            proc_terminate(self::$servidor);
            proc_close(self::$servidor);
            self::$servidor = null;
        }
    }

    protected function setUp(): void
    {
        if (!self::$url) {
            $this->markTestSkipped('No se pudo levantar la API falsa con php -S: ' . (self::$log ? @file_get_contents(self::$log) : ''));
        }
    }

    /** Lo que index.php devuelve para esa dirección. */
    private function vistaPrevia($uri)
    {
        // Las deprecaciones no cuentan como aviso: PHP 8.5 marca curl_close(),
        // y en producción corre 8.3.
        $comando = [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0',
            '-d', 'error_reporting=' . (E_ALL & ~E_DEPRECATED), dirname(__DIR__, 2) . '/Fixtures/vista-previa/correr-index.php', $uri];
        $proceso = proc_open($comando, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['API_URL' => self::$url]);
        $html = stream_get_contents($pipes[1]);
        $errores = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $salida = proc_close($proceso);

        $this->assertSame(0, $salida, $errores);
        $this->assertSame('', $errores, 'index.php no tiene que dejar avisos');

        return $html;
    }

    /** El content de un meta tag, o null si no está. */
    private function meta($html, $propiedad)
    {
        if (!preg_match('/<meta property="' . preg_quote($propiedad, '/') . '" content="([^"]*)">/', $html, $m)) {
            return null;
        }

        return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }

    public function testUnProductoMuestraSuTituloDescripcionEImagen()
    {
        $html = $this->vistaPrevia('/producto/400');

        $this->assertSame('Guía de viaje | La Sala', $this->meta($html, 'og:title'));
        $this->assertSame('Todo lo que hay que saber', $this->meta($html, 'og:description'));
        $this->assertSame('https://img.test/tapa.jpg', $this->meta($html, 'og:image'));
        $this->assertStringContainsString('<title>Guía de viaje | La Sala</title>', $html);
    }

    public function testSinDescripcionMuestraElPrecio()
    {
        $html = $this->vistaPrevia('/producto/401');

        $this->assertSame('Mapa | La Sala', $this->meta($html, 'og:title'));
        $this->assertSame('$ 2.500,50', $this->meta($html, 'og:description'));
        $this->assertNull($this->meta($html, 'og:image'));
    }

    public function testUnProductoGratisSinDescripcionDiceGratisYEscapaElTitulo()
    {
        $html = $this->vistaPrevia('/producto/402');

        $this->assertSame('Pack <gratis> | La Sala', $this->meta($html, 'og:title'));
        $this->assertStringNotContainsString('<gratis>', $html);
        $this->assertSame('Gratis', $this->meta($html, 'og:description'));
    }

    /** Oculto, borrado, sin archivo o inexistente: la API da 404 y queda la genérica. */
    public function testUnProductoQueNoEstaEnVentaDejaLaVistaPreviaGenerica()
    {
        $html = $this->vistaPrevia('/producto/999');

        $this->assertSame('Rezonar - Crea tu Espacio, Comparte Eventos, Conecta', $this->meta($html, 'og:title'));
        $this->assertNull($this->meta($html, 'og:image'));
    }

    /** Lo mismo que ya hacía /evento/<id>, que no tenía test. */
    public function testUnEventoMuestraElEvento()
    {
        $html = $this->vistaPrevia('/evento/300');

        $this->assertSame('El show | La Sala', $this->meta($html, 'og:title'));
        $this->assertSame('Una noche', $this->meta($html, 'og:description'));
        $this->assertSame('https://img.test/afiche.jpg', $this->meta($html, 'og:image'));
    }

    public function testUnSlugSigueMostrandoLaPagina()
    {
        $html = $this->vistaPrevia('/la-sala');

        $this->assertSame('Página la-sala | Rezonar', $this->meta($html, 'og:title'));
    }
}
