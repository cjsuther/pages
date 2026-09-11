<?php

namespace Tests\Unit\Lib;

use Analytics;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeHttpClient;

/**
 * Los informes por página salen de Google Analytics.
 *
 * Lo que se prueba acá es el pedido, no la respuesta de Google: si el filtro
 * sale mal, la página muestra números de otra página —o del sitio entero— y no
 * hay forma de darse cuenta mirando la pantalla.
 */
class AnalyticsTest extends TestCase
{
    /** @var FakeHttpClient */
    private $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new FakeHttpClient();
        // El token se cachea por proceso: sin esto, el primer caso le deja el
        // suyo puesto al resto y ninguno pide uno nuevo.
        Analytics::olvidarToken();
    }

    private function conToken()
    {
        $this->http->responde('oauth2.googleapis.com/token', 200, ['access_token' => 'token-de-prueba']);

        return $this;
    }

    /**
     * Encola lo que contesta Google.
     *
     * Son ocho informes y Google acepta cinco por pedido, así que van en dos
     * llamadas y hay que encolar una respuesta por cada una. Los que el test no
     * declara vuelven vacíos.
     */
    private function conInformes(array $informes)
    {
        foreach (array_chunk(array_pad($informes, 8, $this->informe([])), 5) as $lote) {
            $this->http->responde('analyticsdata.googleapis.com', 200, ['reports' => $lote]);
        }

        return $this;
    }

    /** Un informe de Google con las filas que se le pasen. */
    private function informe(array $filas)
    {
        return ['rows' => $filas];
    }

    private function fila($dimension, $visitas, $personas)
    {
        return [
            'dimensionValues' => [['value' => $dimension]],
            'metricValues' => [['value' => (string) $visitas], ['value' => (string) $personas]],
        ];
    }

    private function pedir($slug = 'la-banda', $dominio = null, $dias = 7, $hoy = '2026-09-10')
    {
        $analytics = new Analytics($this->http);

        return $analytics->dePagina($slug, $dominio, $dias, $hoy);
    }

    private function sinDatos()
    {
        return $this->conInformes([]);
    }

    // ----------------------------------------------------------- autorización

    /**
     * No hay nadie a quien pedirle permiso: el permiso ya está dado del otro
     * lado, cuando se agregó la cuenta de servicio como lectora de la
     * propiedad. Acá sólo se demuestra tener su clave.
     */
    public function testPideElTokenFirmandoUnJwt()
    {
        $this->conToken()->sinDatos()->pedir();

        $campos = $this->http->camposDe('oauth2.googleapis.com/token');

        $this->assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $campos['grant_type']);
        $this->assertCount(3, explode('.', $campos['assertion']));
    }

    public function testElJwtPideSoloLectura()
    {
        $this->conToken()->sinDatos()->pedir();

        $campos = $this->http->camposDe('oauth2.googleapis.com/token');
        $partes = explode('.', $campos['assertion']);
        $claims = json_decode(base64_decode(strtr($partes[1], '-_', '+/')), true);

        $this->assertSame('https://www.googleapis.com/auth/analytics.readonly', $claims['scope']);
        $this->assertSame('informes@test.iam.gserviceaccount.com', $claims['iss']);
    }

    public function testElInformeVaConElTokenPuesto()
    {
        $this->conToken()->sinDatos()->pedir();

        $this->assertContains(
            'Authorization: Bearer token-de-prueba',
            $this->http->cabecerasDe('analyticsdata.googleapis.com')
        );
    }

    /** Sin token no se pide el informe: sería un 401 y un viaje al pedo. */
    public function testSinTokenNoPideElInforme()
    {
        $this->http->responde('oauth2.googleapis.com/token', 401, ['error' => 'invalid_grant']);

        $resultado = $this->pedir();

        $this->assertArrayHasKey('error', $resultado);
        $this->assertFalse($this->http->llamoA('analyticsdata.googleapis.com'));
    }

    // ---------------------------------------------------------------- filtro

    /**
     * El filtro es lo único que separa una página de otra. Si sale mal, quien
     * administra su página ve números que no son suyos y nada falla.
     */
    public function testFiltraPorLaRutaDeLaPagina()
    {
        $this->conToken()->sinDatos()->pedir('la-banda');

        $pedido = $this->http->jsonDe('analyticsdata.googleapis.com');

        foreach ($pedido['requests'] as $informe) {
            $rutas = $informe['dimensionFilter']['orGroup']['expressions'][0]['filter'];
            $this->assertSame('pagePath', $rutas['fieldName']);
            $this->assertSame('/la-banda', $rutas['stringFilter']['value']);
            $this->assertSame('EXACT', $rutas['stringFilter']['matchType']);
        }
    }

    /**
     * Con dominio propio la página vive en la raíz de ese dominio, así que la
     * ruta no alcanza para distinguirla de la home de Rezonar.
     */
    public function testConDominioPropioTambienMiraElDominio()
    {
        $this->conToken()->sinDatos()->pedir('la-banda', 'labanda.com.ar');

        $pedido = $this->http->jsonDe('analyticsdata.googleapis.com');
        $condiciones = $pedido['requests'][0]['dimensionFilter']['orGroup']['expressions'];

        $this->assertCount(2, $condiciones);
        $this->assertSame('hostName', $condiciones[1]['filter']['fieldName']);
        $this->assertSame('labanda.com.ar', $condiciones[1]['filter']['stringFilter']['value']);
    }

    public function testSinDominioPropioNoFiltraPorDominio()
    {
        $this->conToken()->sinDatos()->pedir('la-banda', null);

        $pedido = $this->http->jsonDe('analyticsdata.googleapis.com');

        $this->assertCount(1, $pedido['requests'][0]['dimensionFilter']['orGroup']['expressions']);
    }

    // ---------------------------------------------------------------- pedido

    /**
     * De a cinco, que es el máximo que acepta Google por pedido. Ocho informes
     * en dos llamadas, y no ocho llamadas.
     */
    public function testAgrupaLosInformesEnLaMenorCantidadDeLlamadas()
    {
        $this->conToken()->sinDatos()->pedir();

        $aGoogle = array_values(array_filter($this->http->llamadas, function ($l) {
            return strpos($l['url'], 'analyticsdata') !== false;
        }));

        $this->assertCount(2, $aGoogle);
        $this->assertCount(5, $aGoogle[0]['json']['requests']);
        $this->assertCount(3, $aGoogle[1]['json']['requests']);
        $this->assertStringContainsString('properties/123456789:batchRunReports', $aGoogle[0]['url']);
    }

    /** Y un solo token para las dos llamadas. */
    public function testPideElTokenUnaSolaVez()
    {
        $this->conToken()->sinDatos()->pedir();

        $deToken = array_filter($this->http->llamadas, function ($l) {
            return strpos($l['url'], 'oauth2.googleapis.com') !== false;
        });

        $this->assertCount(1, $deToken);
    }

    /**
     * Un número suelto no dice nada: 300 visitas puede ser el mejor mes o la
     * mitad del anterior. Por eso el período anterior va en el mismo pedido.
     */
    public function testPideTambienElPeriodoAnterior()
    {
        $this->conToken()->sinDatos()->pedir('la-banda', null, 7, '2026-09-10');

        $rangos = $this->http->jsonDe('analyticsdata.googleapis.com')['requests'][0]['dateRanges'];

        $this->assertSame(['startDate' => '2026-09-04', 'endDate' => '2026-09-10', 'name' => 'actual'], $rangos[0]);
        $this->assertSame(['startDate' => '2026-08-28', 'endDate' => '2026-09-03', 'name' => 'previo'], $rangos[1]);
    }

    // -------------------------------------------------------------- respuesta

    public function testDevuelveLosTotalesDeCadaPeriodo()
    {
        $this->conToken()->conInformes([
            $this->informe([$this->fila('actual', 300, 210), $this->fila('previo', 180, 120)]),
            $this->informe([]), $this->informe([]), $this->informe([]), $this->informe([]),
        ]);

        $resultado = $this->pedir();

        $this->assertSame(['visitas' => 300, 'personas' => 210], $resultado['resumen']['actual']);
        $this->assertSame(['visitas' => 180, 'personas' => 120], $resultado['resumen']['previo']);
    }

    /**
     * Google no devuelve los días en cero. Un hueco en la serie se lee como si
     * ese día no se hubiera medido, y lo que pasó es que no entró nadie.
     */
    public function testCompletaLosDiasSinVisitas()
    {
        $this->conToken()->conInformes([
            $this->informe([]),
            $this->informe([$this->fila('20260910', 12, 9)]),
            $this->informe([]), $this->informe([]), $this->informe([]),
        ]);

        $resultado = $this->pedir('la-banda', null, 3, '2026-09-10');

        $this->assertCount(3, $resultado['por_dia']);
        $this->assertSame(['dia' => '2026-09-08', 'visitas' => 0, 'personas' => 0], $resultado['por_dia'][0]);
        $this->assertSame(['dia' => '2026-09-10', 'visitas' => 12, 'personas' => 9], $resultado['por_dia'][2]);
    }

    public function testTraeDeDondeVienenYConQue()
    {
        $this->conToken()->conInformes([
            $this->informe([]), $this->informe([]),
            $this->informe([$this->fila('Organic Social', 90, 70)]),
            $this->informe([$this->fila('mobile', 240, 180)]),
            $this->informe([$this->fila('Buenos Aires', 150, 110)]),
        ]);

        $resultado = $this->pedir();

        $this->assertSame('Organic Social', $resultado['origen'][0]['nombre']);
        $this->assertSame('mobile', $resultado['dispositivo'][0]['nombre']);
        $this->assertSame('Buenos Aires', $resultado['ciudad'][0]['nombre']);
        $this->assertSame(150, $resultado['ciudad'][0]['visitas']);
    }

    /** "(not set)" es cómo Google dice que no sabe. En pantalla no dice nada. */
    public function testTraduceLoQueGoogleNoSupo()
    {
        $this->conToken()->conInformes([
            $this->informe([]), $this->informe([]),
            $this->informe([$this->fila('(not set)', 5, 5)]),
            $this->informe([]), $this->informe([]),
        ]);

        $resultado = $this->pedir();

        $this->assertSame('Sin datos', $resultado['origen'][0]['nombre']);
    }

    /**
     * Google usa varias marcas para lo que no pudo determinar y las tres se
     * traducen igual, así que volvían dos filas "Sin datos" con números
     * distintos. Pasó de verdad en la lista de ciudades.
     */
    public function testJuntaLasFilasQueQuedanConElMismoNombre()
    {
        $this->conToken()->conInformes([
            $this->informe([]), $this->informe([]), $this->informe([]), $this->informe([]),
            $this->informe([
                $this->fila('Buenos Aires', 72, 26),
                $this->fila('(not set)', 46, 32),
                $this->fila('(other)', 4, 4),
            ]),
        ]);

        $ciudades = $this->pedir()['ciudad'];

        $this->assertCount(2, $ciudades);
        $this->assertSame('Sin datos', $ciudades[1]['nombre']);
        $this->assertSame(50, $ciudades[1]['visitas']);
    }

    /** Y al juntarlas, el orden por visitas se mantiene. */
    public function testDespuesDeJuntarlasSiguenOrdenadas()
    {
        $this->conToken()->conInformes([
            $this->informe([]), $this->informe([]), $this->informe([]), $this->informe([]),
            $this->informe([
                $this->fila('(not set)', 40, 30),
                $this->fila('Rosario', 50, 40),
                $this->fila('(other)', 30, 20),
            ]),
        ]);

        $ciudades = $this->pedir()['ciudad'];

        $this->assertSame('Sin datos', $ciudades[0]['nombre']);
        $this->assertSame(70, $ciudades[0]['visitas']);
        $this->assertSame('Rosario', $ciudades[1]['nombre']);
    }

    /**
     * El motivo que da Google suele decir exactamente qué falta —la cuenta sin
     * permiso, la API sin habilitar— y esconderlo deja a alguien adivinando.
     */
    public function testDevuelveElMotivoQueDaGoogle()
    {
        $this->conToken();
        $this->http->responde('analyticsdata.googleapis.com', 403, [
            'error' => ['message' => 'User does not have sufficient permissions for this property.'],
        ]);

        $resultado = $this->pedir();

        $this->assertStringContainsString('sufficient permissions', $resultado['error']);
    }

    // ------------------------------------------------------------ quién te mira

    /** Un informe con dos dimensiones por fila, que es el cruce. */
    private function filaCruce($edad, $genero, $visitas, $personas)
    {
        return [
            'dimensionValues' => [['value' => $edad], ['value' => $genero]],
            'metricValues' => [['value' => (string) $visitas], ['value' => (string) $personas]],
        ];
    }

    private function conDemografia(array $edad, array $genero, array $cruce = [], array $metadata = [])
    {
        $informe = function ($filas) use ($metadata) {
            return array_merge(['rows' => $filas], $metadata ? ['metadata' => $metadata] : []);
        };

        return $this->conInformes([
            $this->informe([]), $this->informe([]), $this->informe([]),
            $this->informe([]), $this->informe([]),
            $informe($edad), $informe($genero), $informe($cruce),
        ]);
    }

    public function testTraduceElGenero()
    {
        $this->conToken()->conDemografia([], [
            $this->fila('male', 90, 60),
            $this->fila('female', 120, 80),
            $this->fila('unknown', 30, 20),
        ]);

        $generos = array_column($this->pedir()['quien']['genero'], 'nombre');

        $this->assertSame(['Mujeres', 'Varones', 'Sin datos'], $generos);
    }

    /**
     * La edad se lee en orden y no por tamaño: ordenada por volumen se pierde
     * la forma, que es justo lo que se mira para saber a quién le hablás.
     */
    public function testLaEdadVaEnOrdenDeEdad()
    {
        $this->conToken()->conDemografia([
            $this->fila('45-54', 10, 8),
            $this->fila('18-24', 90, 70),
            $this->fila('unknown', 200, 150),
            $this->fila('25-34', 50, 40),
        ], []);

        $edades = array_column($this->pedir()['quien']['edad'], 'nombre');

        $this->assertSame(['18-24', '25-34', '45-54', 'Sin datos'], $edades);
    }

    /**
     * El cruce es lo que describe a un público: no "gente de 25 a 34" ni
     * "mujeres", sino las dos cosas a la vez.
     */
    public function testCruzaEdadConGenero()
    {
        $this->conToken()->conDemografia([], [], [
            $this->filaCruce('25-34', 'female', 80, 60),
            $this->filaCruce('25-34', 'male', 40, 30),
        ]);

        $cruce = $this->pedir()['quien']['cruce'];

        $this->assertSame(['edad' => '25-34', 'genero' => 'Mujeres', 'visitas' => 80, 'personas' => 60], $cruce[0]);
        $this->assertSame('Varones', $cruce[1]['genero']);
    }

    /**
     * Sin las señales de Google activadas, el dato no existe y todo vuelve
     * como "unknown". Una tabla así se lee como "no te mira nadie", que es
     * falso: lo que pasa es que Google no lo clasifica.
     */
    public function testAvisaCuandoNoHayNadaClasificado()
    {
        $this->conToken()->conDemografia(
            [$this->fila('unknown', 200, 150)],
            [$this->fila('unknown', 200, 150)]
        );

        $this->assertFalse($this->pedir()['quien']['hay_datos']);
    }

    public function testConDatosClasificadosLoDice()
    {
        $this->conToken()->conDemografia([$this->fila('25-34', 50, 40)], []);

        $this->assertTrue($this->pedir()['quien']['hay_datos']);
    }

    /**
     * Google retiene filas cuando son pocas personas, para que no se pueda
     * reconocer a nadie. Le pasa a cualquier página chica, y hay que decirlo:
     * si no, una tabla incompleta se lee como si fueran todos los datos.
     */
    public function testAvisaCuandoGoogleRetuvoFilas()
    {
        $this->conToken()->conDemografia([], [], [], ['subjectToThresholding' => true]);

        $this->assertTrue($this->pedir()['quien']['retenido']);
    }

    public function testSinRetencionNoAvisaNada()
    {
        $this->conToken()->conDemografia([$this->fila('25-34', 50, 40)], []);

        $this->assertFalse($this->pedir()['quien']['retenido']);
    }

    public function testPideEdadGeneroYElCruce()
    {
        $this->conToken()->sinDatos()->pedir();

        $aGoogle = array_values(array_filter($this->http->llamadas, function ($l) {
            return strpos($l['url'], 'analyticsdata') !== false;
        }));
        $dimensiones = array_map(function ($informe) {
            return array_column($informe['dimensions'], 'name');
        }, $aGoogle[1]['json']['requests']);

        $this->assertSame([['userAgeBracket'], ['userGender'], ['userAgeBracket', 'userGender']], $dimensiones);
    }

    // ----------------------------------------------------------------- ventana

    public function testLaVentanaTieneUnTecho()
    {
        $this->assertSame(365, Analytics::diasValidos(5000));
        $this->assertSame(30, Analytics::diasValidos(0));
        $this->assertSame(30, Analytics::diasValidos('cualquier cosa'));
        $this->assertSame(7, Analytics::diasValidos('7'));
    }

    public function testEstaConfiguradoConLasCredencialesDePrueba()
    {
        $this->assertTrue(Analytics::configurado());
        $this->assertTrue((new Analytics($this->http))->estaListo());
    }
}
