<?php

namespace App\Tests;

use App\Exceptions\ExecFailed;
use App\Model\DockerData;
use App\Model\GlobalConfig;
use App\Service\Configurator;
use App\Service\Docker;

final class DockerComposeFallbackTest extends MchefTestCase {

    public function testBuildImageWithComposeFallsBackAndRewritesConfig(): void {
        $service = new class extends Docker {
            public array $commands = [];
            public bool $firstShouldFail = true;

            public function __construct() {
                parent::__construct();
            }

            protected function exec(string $cmd, ?string $errorMsg = null, ?bool $silent = false): string {
                $this->commands[] = $cmd;
                if (str_contains($cmd, ' build')) {
                    if ($this->firstShouldFail) {
                        $this->firstShouldFail = false;
                        throw new ExecFailed('first failed', 0, $cmd);
                    }
                }
                return '';
            }
        };

        $configurator = $this->getMockBuilder(Configurator::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getMainConfig', 'setMainConfigField'])
            ->getMock();

        $mainConfig = new GlobalConfig(dockerComposeCommand: 'docker compose');
        $configurator->method('getMainConfig')->willReturn($mainConfig);
        $configurator->expects($this->once())
            ->method('setMainConfigField')
            ->with('dockerComposeCommand', 'docker-compose');

        $this->applyMockedServices(['configurator' => $configurator], $service);

        $dockerData = new DockerData(
            volumes: [],
            moodlePath: '/tmp/moodle',
            usePublicPath: false,
            containerName: 'mchef-moodle',
            moodleTag: 'v4.5.0',
            phpVersion: '8.2'
        );

        $service->buildImageWithCompose('/tmp/docker-compose.yml', $dockerData, 'my/image:tag', '/tmp', false);

        $this->assertGreaterThanOrEqual(2, count($service->commands));
        $this->assertStringContainsString('docker compose', $service->commands[0]);
        $this->assertStringContainsString('docker-compose', $service->commands[1]);
    }

    public function testBuildImageWithComposeThrowsWhenBothCommandsFail(): void {
        $service = new class extends Docker {
            public function __construct() {
                parent::__construct();
            }

            protected function exec(string $cmd, ?string $errorMsg = null, ?bool $silent = false): string {
                if (str_contains($cmd, ' build')) {
                    throw new ExecFailed('always fails', 0, $cmd);
                }
                return '';
            }
        };

        $configurator = $this->getMockBuilder(Configurator::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getMainConfig', 'setMainConfigField'])
            ->getMock();

        $mainConfig = new GlobalConfig(dockerComposeCommand: 'docker compose');
        $configurator->method('getMainConfig')->willReturn($mainConfig);
        $configurator->expects($this->never())->method('setMainConfigField');

        $this->applyMockedServices(['configurator' => $configurator], $service);

        $dockerData = new DockerData(
            volumes: [],
            moodlePath: '/tmp/moodle',
            usePublicPath: false,
            containerName: 'mchef-moodle',
            moodleTag: 'v4.5.0',
            phpVersion: '8.2'
        );

        $this->expectException(ExecFailed::class);
        $service->buildImageWithCompose('/tmp/docker-compose.yml', $dockerData, 'my/image:tag', '/tmp', false);
    }
}
