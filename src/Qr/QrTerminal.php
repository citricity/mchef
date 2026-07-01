<?php

declare(strict_types=1);

namespace App\Qr;

use chillerlan\QRCode\Output\QROutputAbstract;

final class QrTerminal extends QROutputAbstract {

    public static function moduleValueIsValid(mixed $value): bool {
        return is_bool($value);
    }

    protected function prepareModuleValue(mixed $value): mixed {
        return (bool)$value;
    }

    protected function getDefaultModuleValue(bool $isDark): mixed {
        return $isDark;
    }

    public function dump(string|null $file = null): string {
        $output = $this->options->eol;

        for ($y = 0; $y < $this->moduleCount; $y += 2) {
            $output .= '  ';

            for ($x = 0; $x < $this->moduleCount; $x++) {
                $top = $this->matrix->check($x, $y);
                $bottom = ($y + 1 < $this->moduleCount)
                    ? $this->matrix->check($x, $y + 1)
                    : false;

                $output .= match ([$top, $bottom]) {
                    [true, true] => '█',
                    [true, false] => '▀',
                    [false, true] => '▄',
                    default => ' ',
                };
            }

            $output .= $this->options->eol;
        }

        return $output . $this->options->eol;
    }

}
