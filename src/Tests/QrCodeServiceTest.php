<?php

use App\Service\QrCodeService;

final class QrCodeServiceTest extends \App\Tests\MchefTestCase
{
    private QrCodeService $service;

    protected function setUp(): void {
        parent::setUp();
        $this->service = QrCodeService::instance();
    }

    public function testGenerateQrCodeRendersNonEmptyOutput(): void {
        $output = $this->service->generateQrCode('https://testuser.github.io/mchef-urls/index.html?linkHash=abc');

        $this->assertNotEmpty($output);
        // The terminal renderer draws modules with Unicode half-block characters.
        $this->assertMatchesRegularExpression('/[\x{2588}\x{2580}\x{2584}]/u', $output);
    }

    public function testGenerateQrCodeVariesWithInput(): void {
        $a = $this->service->generateQrCode('https://example.com/a');
        $b = $this->service->generateQrCode('https://example.com/completely-different-target');

        $this->assertNotEquals($a, $b);
    }
}
