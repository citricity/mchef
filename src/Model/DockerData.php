<?php

namespace App\Model;

class DockerData extends Recipe {
    public function __construct(
        public array $volumes,
        public ?string $dockerFile = null,
        public ?int $hostPort = null, // Used by composer, not dockerfile
        public ?array $pluginsForDocker = null, // plugin information for dockerfile cloning
        public ?int $proxyModePort = null, // Port used in proxy mode
        ...$args,
    ) {
        parent::__construct(...self::cleanConstructArgs($args));
    }
}
