<?php

namespace App\Model;

class MoodleConfig extends AbstractModel {
    public function __construct(
        public string $theme = self::UNSET,
        public string $lang = self::UNSET,
    ) {}
}
