<?php

namespace Tests\Feature;

use App\Domains\Invoicing\Services\QrisDynamicService;
use Tests\TestCase;

class QrisDynamicTest extends TestCase
{
    private const STATIC = '00020101021126610014COM.GO-JEK.WWW01189360091434513452190210G4513452190303UMI51440014ID.CO.QRIS.WWW0215ID10266187094590303UMI5204899953033605802ID5908MCIMEDIA6008SALATIGA61055073262070703A0163048635';

    public function test_crc16_validates_original(): void
    {
        $body = substr(self::STATIC, 0, -4);
        $crc = substr(self::STATIC, -4);

        $this->assertEquals($crc, QrisDynamicService::crc16($body));
    }

    public function test_to_dynamic_produces_valid_payload(): void
    {
        $svc = new QrisDynamicService();
        $dyn = $svc->toDynamic(self::STATIC, 1500123);

        // Marker dinamis
        $this->assertStringContainsString('010212', $dyn);
        $this->assertStringNotContainsString('010211', $dyn);

        // Field nominal benar
        $this->assertStringContainsString('54071500123', $dyn);

        // Tidak ada 6304 ganda
        $this->assertStringNotContainsString('63046304', $dyn);

        // CRC valid
        $body = substr($dyn, 0, -4);
        $crc = substr($dyn, -4);
        $this->assertEquals($crc, QrisDynamicService::crc16($body));
    }

    public function test_parse_amount(): void
    {
        $svc = new QrisDynamicService();

        $this->assertEquals(1500123, $svc->parseAmount('Dana masuk Rp1.500.123 dari QRIS'));
        $this->assertEquals(1500123, $svc->parseAmount('Rp 1.500.123 telah diterima'));
        $this->assertEquals(0, $svc->parseAmount('tidak ada nominal'));
    }

    public function test_webhook_rejects_bad_secret(): void
    {
        config(['qris.webhook_secret' => 'rahasia']);

        $svc = new QrisDynamicService();
        $this->assertFalse($svc->handleNotify(['text' => 'Rp1.500.123'], 'salah'));
    }
}
