<?php

namespace Tests\Unit\Lib;

use DeviceInfo;
use PHPUnit\Framework\TestCase;

class DeviceInfoTest extends TestCase
{
    const IPHONE_SAFARI = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
    const IPHONE_CHROME = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/120.0 Mobile/15E148 Safari/604.1';
    const ANDROID       = 'Mozilla/5.0 (Linux; Android 14; SM-A546E) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Mobile Safari/537.36';
    const ESCRITORIO    = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/126.0 Safari/537.36';

    /**
     * @dataProvider plataformas
     */
    public function testPlataforma($ua, $esperada)
    {
        $this->assertSame($esperada, DeviceInfo::plataforma($ua));
    }

    public function plataformas()
    {
        return [
            'iPhone' => [self::IPHONE_SAFARI, 'iOS'],
            'iPad' => ['Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) Safari/604.1', 'iOS'],
            'Android' => [self::ANDROID, 'Android'],
            'escritorio' => [self::ESCRITORIO, 'Desktop'],
            'vacío' => ['', 'Desktop'],
            'null' => [null, 'Desktop'],
        ];
    }

    /**
     * @dataProvider marcas
     */
    public function testMarca($ua, $esperada)
    {
        $this->assertSame($esperada, DeviceInfo::marca($ua));
    }

    public function marcas()
    {
        return [
            'Samsung' => ['Mozilla/5.0 (Linux; Android 14; SM-A546E)', 'Samsung'],
            'Xiaomi Redmi' => ['Mozilla/5.0 (Linux; Android 13; Redmi Note 12)', 'Xiaomi'],
            'Xiaomi POCO' => ['Mozilla/5.0 (Linux; Android 13; POCO X5)', 'Xiaomi'],
            'Motorola' => ['Mozilla/5.0 (Linux; Android 13; moto g54)', 'Motorola'],
            'Huawei' => ['Mozilla/5.0 (Linux; Android 12; HUAWEI P40)', 'Huawei'],
            'Honor es Huawei' => ['Mozilla/5.0 (Linux; Android 12; Honor 90)', 'Huawei'],
            'Oppo' => ['Mozilla/5.0 (Linux; Android 13; CPH2381)', 'Oppo'],
            'Realme' => ['Mozilla/5.0 (Linux; Android 13; RMX3771)', 'Realme'],
            'desconocida' => [self::IPHONE_SAFARI, null],
            'vacío' => ['', null],
        ];
    }

    /**
     * En iOS sólo Safari puede instalar la PWA; los demás navegadores usan el
     * mismo motor pero no instalan (guía §2.3).
     *
     * @dataProvider navegadoresIOS
     */
    public function testEsSafariEnIOS($ua, $esperado)
    {
        $this->assertSame($esperado, DeviceInfo::esSafariEnIOS($ua));
    }

    public function navegadoresIOS()
    {
        return [
            'Safari en iPhone' => [self::IPHONE_SAFARI, true],
            'Chrome en iPhone' => [self::IPHONE_CHROME, false],
            'Firefox en iPhone' => ['Mozilla/5.0 (iPhone) FxiOS/120.0 Mobile Safari/604.1', false],
            'Edge en iPhone' => ['Mozilla/5.0 (iPhone) EdgiOS/120.0 Mobile Safari/604.1', false],
            'Opera en iPhone' => ['Mozilla/5.0 (iPhone) OPiOS/120.0 Mobile Safari/604.1', false],
            'Android no aplica' => [self::ANDROID, true],
            'escritorio no aplica' => [self::ESCRITORIO, true],
        ];
    }
}
