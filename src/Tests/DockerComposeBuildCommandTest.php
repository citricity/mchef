<?php

namespace App\Tests;

use App\Exceptions\ExecFailed;
use App\Model\DockerData;
use App\Model\GlobalConfig;
use App\Service\Configurator;
use App\Service\Docker;

final class DockerComposeBuildCommandTest extends MchefTestCase {

    public function testBuildImageWithComposeUsesResolvedCommandOnly(): void {
        $service = new class extends Docker {
            public array $commands = [];

            public function __construct() {
                parent::__construct();
            }

            protected function exec(string $cmd, ?string $errorMsg = null, ?bool $silent = false): string {
                $this->commands[] = $cmd;
                return '';
            }

            protected function execPassthru(string $cmd, ?string $errorMsg = null): void {
                $this->commands[] = $cmd;
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

        $service->buildImageWithCompose('/tmp/docker-compose.yml', $dockerData, 'my/image:tag', '/tmp', false);

        $this->assertGreaterThanOrEqual(1, count($service->commands));
        $this->assertStringContainsString('docker compose', $service->commands[0]);
        $this->assertStringNotContainsString('docker-compose --project-directory', $service->commands[0]);
    }

    public function testBuildImageWithComposeThrowsWhenResolvedCommandFails(): void {
        $service = new class extends Docker {
            public array $commands = [];

            public function __construct() {
                parent::__construct();
            }

            protected function exec(string $cmd, ?string $errorMsg = null, ?bool $silent = false): string {
                $this->commands[] = $cmd;
                return '';
            }

            protected function execPassthru(string $cmd, ?string $errorMsg = null): void {
                $this->commands[] = $cmd;
                if (str_contains($cmd, ' build')) {
                    throw new ExecFailed('build fails', 0, $cmd);
                }
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

        $this->assertCount(1, array_filter($service->commands, fn($cmd) => str_contains($cmd, ' build')));
    }
}
