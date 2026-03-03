<?php

namespace App\Tests;

use App\Model\RegistryInstance;
use App\Service\ProxyService;

class ProxyModeTest extends MchefTestCase {

    public function testRegistryInstanceWithProxyPort(): void {
        $instance = new RegistryInstance('test-uuid', '/test/path', 'test-prefix', 8100);

        $this->assertEquals('test-uuid', $instance->uuid);
        $this->assertEquals('/test/path', $instance->recipePath);
        $this->assertEquals('test-prefix', $instance->containerPrefix);
        $this->assertEquals(8100, $instance->proxyModePort);
    }

    public function testRegistryInstanceWithoutProxyPort(): void {
        $instance = new RegistryInstance('test-uuid', '/test/path', 'test-prefix');

        $this->assertEquals('test-uuid', $instance->uuid);
        $this->assertEquals('/test/path', $instance->recipePath);
        $this->assertEquals('test-prefix', $instance->containerPrefix);
        $this->assertNull($instance->proxyModePort);
    }

    public function testProxyServiceIsProxyModeEnabled(): void {
        $proxyService = ProxyService::instance();

        // This test depends on the current global config
        // We're just testing that the method doesn't throw an error
        $result = $proxyService->isProxyModeEnabled();
        $this->assertIsBool($result);
    }

    public function testProxyServiceConfigPath(): void {
        $proxyService = ProxyService::instance();
        $configPath = $proxyService->getProxyConfigPath();

        $this->assertIsString($configPath);
        $this->assertStringContainsString('proxy.conf', $configPath);
    }

    public function testProxyContainerConstants(): void {
        $this->assertEquals('mchef-proxy', ProxyService::PROXY_CONTAINER_NAME);
        $this->assertEquals(80, ProxyService::PROXY_PORT);
    }

    public function testIsPort80UsedByMchefProxyReturnsBool(): void {
        $proxyService = ProxyService::instance();
        $result = $proxyService->isPort80UsedByMchefProxy();
        $this->assertIsBool($result);
    }

    public function testIsPort80InUseReturnsBool(): void {
        $proxyService = ProxyService::instance();
        $result = $proxyService->isPort80InUse();
        $this->assertIsBool($result);
    }

    public function testWarnIfPort80BlockedForProxyDoesNotThrow(): void {
        $proxyService = ProxyService::instance();
        $proxyService->warnIfPort80BlockedForProxy();
        $this->addToAssertionCount(1);
    }

    public function testIsPort80UsedByMchefProxyParsesDockerPsOutput(): void {
        // Test the logic: when docker ps returns mchef-proxy with port 80, we expect true.
        // We cannot mock exec() easily, so we test with real ProxyService; outcome depends on env.
        $proxyService = ProxyService::instance();
        $running = $proxyService->isProxyContainerRunning();
        $onPort80 = $proxyService->isPort80UsedByMchefProxy();
        if ($running) {
            $this->assertTrue($onPort80, 'If mchef-proxy is running it should be bound to port 80');
        }
        $this->assertIsBool($onPort80);
    }
}
