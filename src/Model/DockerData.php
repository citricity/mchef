<?php

namespace App\Model;

class DockerData extends Recipe {
    public function __construct(
        public array $volumes,
        public string $moodlePath,
        public bool $usePublicPath,
        public ?string $dockerFile = null,
        public ?int $hostPort = null, // Used by composer, not dockerfile
        public ?array $pluginsForDocker = null, // plugin information for dockerfile cloning
        public ?int $proxyModePort = null, // Port used in proxy mode
        public bool $reposUseSsh = false, // Whether any repos use SSH
        ...$args,
    ) {
        parent::__construct(...self::cleanConstructArgs($args));
    }
}
