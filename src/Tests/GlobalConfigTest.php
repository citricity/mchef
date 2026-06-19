<?php

namespace App\Tests;

use App\Model\GlobalConfig;
use App\Service\Configurator;

final class GlobalConfigTest extends MchefTestCase {

    public function testDockerComposeCommandDefaultsToNull(): void {
        $config = new GlobalConfig();
        $this->assertNull($config->dockerComposeCommand);
    }

    public function testDockerComposeCommandPersistsThroughJsonRoundtrip(): void {
        $configurator = Configurator::instance(); // This will auto initialize the config dir if not present.
        $configPath = $configurator->configDir() . '/global_config_roundtrip.json';
        $config = new GlobalConfig(dockerComposeCommand: 'docker-compose');

        $config->toJSONFile($configPath);

        $loaded = GlobalConfig::fromJSONFile($configPath);
        $this->assertInstanceOf(GlobalConfig::class, $loaded);
        $this->assertEquals('docker-compose', $loaded->dockerComposeCommand);
    }
}
