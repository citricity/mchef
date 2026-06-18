<?php

namespace App\Tests;

use App\Model\GlobalConfig;
use App\Service\Configurator;
use App\Service\Dependencies;
use App\Traits\CallRestrictedMethodTrait;
use PHPUnit\Framework\MockObject\MockObject;

final class DependenciesComposeResolutionTest extends MchefTestCase {
    use CallRestrictedMethodTrait;

    private Dependencies $dependencies;
    private MockObject $configurator;
    private string $originalPath;
    private array $tempDirs = [];

    protected function setUp(): void {
        parent::setUp();

        $this->originalPath = getenv('PATH') ?: '';

        $this->dependencies = Dependencies::instance(true);
        $this->configurator = $this->getMockBuilder(Configurator::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getMainConfig', 'setMainConfigField'])
            ->getMock();

        $this->applyMockedServices(['configurator' => $this->configurator], $this->dependencies);
    }

    protected function tearDown(): void {
        putenv('PATH=' . $this->originalPath);
        foreach ($this->tempDirs as $dir) {
            $files = glob($dir . '/*') ?: [];
            foreach ($files as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
        parent::tearDown();
    }

    private function makeFakeDockerBins(array $cfg): void {
        $dir = sys_get_temp_dir() . '/mchef_compose_test_' . uniqid('', true);
        mkdir($dir, 0755, true);
        $this->tempDirs[] = $dir;

        $dockerScript = "#!/bin/sh\n" .
            "if [ \"$1\" = \"compose\" ] && [ \"$2\" = \"version\" ] && [ \"$3\" = \"-f\" ] && [ \"$4\" = \"json\" ]; then\n" .
            "  echo \"" . addslashes($cfg['docker_compose_json_output'] ?? '') . "\"\n" .
            "  exit " . intval($cfg['docker_compose_json_exit'] ?? 1) . "\n" .
            "fi\n" .
            "if [ \"$1\" = \"compose\" ] && [ \"$2\" = \"version\" ]; then\n" .
            "  echo \"" . addslashes($cfg['docker_compose_plain_output'] ?? '') . "\"\n" .
            "  exit " . intval($cfg['docker_compose_plain_exit'] ?? 1) . "\n" .
            "fi\n" .
            "if [ \"$1\" = \"version\" ]; then\n" .
            "  echo \"Docker version 27.0.0\"\n" .
            "  exit 0\n" .
            "fi\n" .
            "exit 1\n";

        $dockerComposeScript = "#!/bin/sh\n" .
            "if [ \"$1\" = \"version\" ] && [ \"$2\" = \"-f\" ] && [ \"$3\" = \"json\" ]; then\n" .
            "  echo \"" . addslashes($cfg['docker_dash_json_output'] ?? '') . "\"\n" .
            "  exit " . intval($cfg['docker_dash_json_exit'] ?? 1) . "\n" .
            "fi\n" .
            "if [ \"$1\" = \"version\" ]; then\n" .
            "  echo \"" . addslashes($cfg['docker_dash_plain_output'] ?? '') . "\"\n" .
            "  exit " . intval($cfg['docker_dash_plain_exit'] ?? 1) . "\n" .
            "fi\n" .
            "exit 1\n";

        file_put_contents($dir . '/docker', $dockerScript);
        file_put_contents($dir . '/docker-compose', $dockerComposeScript);
        chmod($dir . '/docker', 0755);
        chmod($dir . '/docker-compose', 0755);

        putenv('PATH=' . $dir . ':' . $this->originalPath);
    }

    private function getLastComposeError(): string {
        $reflection = new \ReflectionClass($this->dependencies);
        $property = $reflection->getProperty('lastComposeResolutionError');
        return (string) $property->getValue($this->dependencies);
    }

    public function testResolveComposeFallsBackToDockerComposeBinaryAndPersists(): void {
        $this->makeFakeDockerBins([
            'docker_compose_json_exit' => 1,
            'docker_compose_plain_exit' => 1,
            'docker_dash_json_exit' => 1,
            'docker_dash_plain_exit' => 0,
            'docker_dash_plain_output' => 'Docker Compose version 5.1.4',
        ]);

        $config = new GlobalConfig(dockerComposeCommand: 'docker compose');
        $this->configurator->method('getMainConfig')->willReturn($config);
        $this->configurator->expects($this->once())
            ->method('setMainConfigField')
            ->with('dockerComposeCommand', 'docker-compose');

        $resolved = $this->callRestricted($this->dependencies, 'resolveComposeCommand', []);

        $this->assertEquals('docker-compose', $resolved);
        $this->assertEquals('ok', $this->getLastComposeError());
    }

    public function testResolveComposeMarksNotAvailableWhenBothCommandsFail(): void {
        $this->makeFakeDockerBins([
            'docker_compose_json_exit' => 1,
            'docker_compose_plain_exit' => 1,
            'docker_dash_json_exit' => 1,
            'docker_dash_plain_exit' => 1,
        ]);

        $config = new GlobalConfig(dockerComposeCommand: 'docker compose');
        $this->configurator->method('getMainConfig')->willReturn($config);
        $this->configurator->expects($this->never())->method('setMainConfigField');

        $resolved = $this->callRestricted($this->dependencies, 'resolveComposeCommand', []);

        $this->assertNull($resolved);
        $this->assertEquals('not_available', $this->getLastComposeError());
    }

    public function testResolveComposeMarksVersionNotParsable(): void {
        $this->makeFakeDockerBins([
            'docker_compose_json_exit' => 0,
            'docker_compose_json_output' => '{}',
            'docker_compose_plain_exit' => 0,
            'docker_compose_plain_output' => 'Docker Compose version unknown',
            'docker_dash_json_exit' => 0,
            'docker_dash_json_output' => '{}',
            'docker_dash_plain_exit' => 0,
            'docker_dash_plain_output' => 'Docker Compose version unknown',
        ]);

        $config = new GlobalConfig();
        $this->configurator->method('getMainConfig')->willReturn($config);
        $this->configurator->expects($this->never())->method('setMainConfigField');

        $resolved = $this->callRestricted($this->dependencies, 'resolveComposeCommand', []);

        $this->assertNull($resolved);
        $this->assertEquals('version_not_parsable', $this->getLastComposeError());
    }

    public function testResolveComposeMarksUnsupportedWhenOnlyV1Found(): void {
        $this->makeFakeDockerBins([
            'docker_compose_json_exit' => 1,
            'docker_compose_plain_exit' => 0,
            'docker_compose_plain_output' => 'Docker Compose version 1.29.2',
            'docker_dash_json_exit' => 1,
            'docker_dash_plain_exit' => 0,
            'docker_dash_plain_output' => 'Docker Compose version 1.29.2',
        ]);

        $config = new GlobalConfig();
        $this->configurator->method('getMainConfig')->willReturn($config);
        $this->configurator->expects($this->never())->method('setMainConfigField');

        $resolved = $this->callRestricted($this->dependencies, 'resolveComposeCommand', []);

        $this->assertNull($resolved);
        $this->assertEquals('version_unsupported', $this->getLastComposeError());
    }
}
