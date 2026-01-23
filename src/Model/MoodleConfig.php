<?php

namespace App\Model;

class MoodleConfig extends AbstractModel {
    public function __construct(
        public string $prefix = 'mdl_',
        public string $directorypermissions = '02777',
        public string $admin = 'admin',
        public string $lang = self::UNSET,
        public string $timezone = self::UNSET,
        public string $defaultblocks = self::UNSET,
        public bool $sslproxy = false
    ) { }
}
