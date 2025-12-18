<?php

namespace App\Constants;

/**
 * Constants for sample data size values matching Moodle's tool_generator
 */
class SampleDataSize {
    public const XS = 'XS';
    public const S = 'S';
    public const M = 'M';
    public const L = 'L';
    public const XL = 'XL';
    public const XXL = 'XXL';

    /**
     * Get all valid size values
     * @return string[]
     */
    public static function getAll(): array {
        return [
            self::XS,
            self::S,
            self::M,
            self::L,
            self::XL,
            self::XXL,
        ];
    }

    /**
     * Check if a size value is valid
     * @param string|null $size
     * @return bool
     */
    public static function isValid(?string $size): bool {
        if ($size === null) {
            return false;
        }
        return in_array(strtoupper($size), self::getAll(), true);
    }

    /**
     * Normalize size value to uppercase
     * @param string|null $size
     * @return string|null
     */
    public static function normalize(?string $size): ?string {
        if ($size === null) {
            return null;
        }
        $upper = strtoupper($size);
        return self::isValid($upper) ? $upper : null;
    }
}

