<?php

namespace App\Service;

use App\Qr\QrTerminal;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

final class QrCodeService extends AbstractService {
    public static function instance(): QrCodeService {
        return self::setup_singleton();
    }
    public function generateQrCode(string $text): string {
       $options = new QROptions([
            'outputInterface' => QrTerminal::class,
            'quietzoneSize' => 2,
            'eol' => PHP_EOL,
        ]);
        return (new QRCode($options))->render($text);
    }
}