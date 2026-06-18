<?php

namespace App\Tests;

use App\Model\GlobalConfig;

final class GlobalConfigTest extends MchefTestCase {

    public function testDockerComposeCommandDefaultsToNull(): void {
        $config = new GlobalConfig();
        $this->assertNull($config->dockerComposeCommand);
    }

    public function testDockerComposeCommandPersistsThroughJsonRoundtrip(): void {
        $configPath = sys_get_temp_dir() . '/mchef_test_config/global_config_roundtrip.json';
        $config = new GlobalConfig(dockerComposeCommand: 'docker-compose');

        $config->toJSONFile($configPath);

        $loaded = GlobalConfig::fromJSONFile($configPath);
        $this->assertInstanceOf(GlobalConfig::class, $loaded);
        $this->assertEquals('docker-compose', $loaded->dockerComposeCommand);
    }
}
