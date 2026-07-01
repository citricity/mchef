<?php

use App\Model\GlobalConfig;
use App\Service\Configurator;
use App\Service\PlaygroundSnapshotService;

final class PlaygroundSnapshotServiceTest extends \App\Tests\MchefTestCase
{
    private PlaygroundSnapshotService $service;

    protected function setUp(): void {
        parent::setUp();
        $this->service = $this->makeService();
    }

    public function testDeriveChannelFromBranchTag(): void {
        $this->assertEquals('MOODLE_500_STABLE', $this->service->deriveChannel('MOODLE_500_STABLE'));
        $this->assertEquals('MOODLE_404_STABLE', $this->service->deriveChannel('MOODLE_404_STABLE'));
        $this->assertEquals('main', $this->service->deriveChannel('main'));
        $this->assertEquals('dev', $this->service->deriveChannel('dev'));
    }

    public function testDeriveChannelFromSemver(): void {
        $this->assertEquals('MOODLE_500_STABLE', $this->service->deriveChannel('v5.0.8'));
        $this->assertEquals('MOODLE_500_STABLE', $this->service->deriveChannel('v5.0.0'));
        $this->assertEquals('MOODLE_405_STABLE', $this->service->deriveChannel('v4.5.2'));
        $this->assertEquals('MOODLE_404_STABLE', $this->service->deriveChannel('v4.4.0'));
        $this->assertEquals('MOODLE_401_STABLE', $this->service->deriveChannel('v4.1.0'));
    }

    public function testDeriveChannelReturnsNullForUnknownFormat(): void {
        $this->assertNull($this->service->deriveChannel('unknown-format'));
        $this->assertNull($this->service->deriveChannel('latest'));
    }

    public function testResolvePlaygroundPathFromConfig(): void {
        $tmpDir  = sys_get_temp_dir() . '/fake-playground-' . uniqid();
        mkdir($tmpDir, 0755, true);

        $service = $this->makeService(playgroundLocalPath: $tmpDir);
        $this->assertEquals($tmpDir, $service->resolvePlaygroundPath());

        rmdir($tmpDir);
    }

    public function testResolvePlaygroundPathReturnsNullWhenConfiguredPathMissing(): void {
        $service = $this->makeService(playgroundLocalPath: '/nonexistent/path/to/playground');
        $this->assertNull($service->resolvePlaygroundPath());
    }

    public function testResolvePlaygroundPathFallsBackToSiblingMoodlePlayground(): void {
        $service = $this->makeService(playgroundLocalPath: null);
        $result  = $service->resolvePlaygroundPath();

        // PlaygroundSnapshotService hardcodes __DIR__/../../../moodle-playground.
        // From src/Service/ that resolves to playground/moodle-playground.
        // From the test file (src/Tests/) we compute the same expected path:
        $expectedFallback = realpath(dirname(__FILE__, 4) . '/moodle-playground');

        if ($expectedFallback !== false && is_dir($expectedFallback)) {
            $this->assertEquals(
                $expectedFallback,
                $result,
                'Fallback should return the sibling moodle-playground directory'
            );
        } else {
            $this->assertNull($result, 'Fallback should return null when sibling does not exist');
        }
    }

    private function makeService(?string $playgroundLocalPath = null): PlaygroundSnapshotService {
        $service = PlaygroundSnapshotService::instance(true);

        $mockConfig = new GlobalConfig(playgroundLocalPath: $playgroundLocalPath);
        $mockConfigurator = $this->createMock(Configurator::class);
        $mockConfigurator->method('getMainConfig')->willReturn($mockConfig);

        $this->applyMockedServices(['configuratorService' => $mockConfigurator], $service);

        return $service;
    }
}
