<?php

namespace App\Tests;

use App\Model\GlobalConfig;
use App\Service\Configurator;

final class ConfiguratorTest extends MchefTestCase {

    public function testSetMainConfigFieldSetsValidStringField(): void {
        $configurator = Configurator::instance(true);

        $configurator->setMainConfigField('lang', 'fr');

        $configPath = $configurator->configDir() . '/main.json';
        $raw = file_get_contents($configPath);
        $data = json_decode($raw, true);

        $this->assertSame('fr', $data['lang']);
    }

    public function testSetMainConfigFieldThrowsForInvalidField(): void {
        $configurator = Configurator::instance(true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid config field');

        $configurator->setMainConfigField('notARealField', 'value');
    }

    public function testSetMainConfigFieldThrowsForInvalidBooleanType(): void {
        $configurator = Configurator::instance(true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("expects a boolean");

        $configurator->setMainConfigField('useProxy', 'yes');
    }

    public function testSetMainConfigFieldCastsBackedEnumValue(): void {
        $configurator = Configurator::instance(true);

        $configurator->setMainConfigField('debugMode', 'verbose');

        $configPath = $configurator->configDir() . '/main.json';
        $raw = file_get_contents($configPath);
        $data = json_decode($raw, true);

        $this->assertSame('verbose', $data['debugMode']);
    }

    public function testWriteMainConfigInvalidatesStaticCache(): void {
        $configurator = Configurator::instance(true);

        $reflection = new \ReflectionClass(Configurator::class);
        $configProp = $reflection->getProperty('config');

        $configProp->setValue(null, new GlobalConfig(lang: 'de'));
        $this->assertInstanceOf(GlobalConfig::class, $configProp->getValue());

        $configurator->writeMainConfig(new GlobalConfig(lang: 'fr'));

        $this->assertNull($configProp->getValue());
    }
}
