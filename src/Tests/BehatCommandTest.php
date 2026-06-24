<?php

namespace App\Tests;

use App\Command\Behat;
use App\Helpers\SplitbrainWrapper;
use ReflectionClass;
use splitbrain\phpcli\Options;

final class BehatCommandTest extends MchefTestCase {

    private Behat $behatCommand;
    private ReflectionClass $reflection;

    protected function setUp(): void {
        parent::setUp();

        $this->behatCommand = Behat::instance();
        $this->reflection = new ReflectionClass($this->behatCommand);

        $cliProperty = $this->reflection->getProperty('cli');
        $cliProperty->setValue($this->behatCommand, $this->cli);

        $openerProperty = $this->reflection->getProperty('viewUrlOpener');
        $openerProperty->setValue($this->behatCommand, null);

        $probeProperty = $this->reflection->getProperty('seleniumReadyProbe');
        $probeProperty->setValue($this->behatCommand, null);

        $restarterProperty = $this->reflection->getProperty('seleniumRestarter');
        $restarterProperty->setValue($this->behatCommand, null);
    }

    protected function tearDown(): void {
        $openerProperty = $this->reflection->getProperty('viewUrlOpener');
        $openerProperty->setValue($this->behatCommand, null);

        $probeProperty = $this->reflection->getProperty('seleniumReadyProbe');
        $probeProperty->setValue($this->behatCommand, null);

        $restarterProperty = $this->reflection->getProperty('seleniumRestarter');
        $restarterProperty->setValue($this->behatCommand, null);
        parent::tearDown();
    }

    public function testMaybeOpenViewOpensUrlWhenEnabled(): void {
        $capturedUrl = null;

        $openerProperty = $this->reflection->getProperty('viewUrlOpener');
        $openerProperty->setValue($this->behatCommand, function(string $url) use (&$capturedUrl): void {
            $capturedUrl = $url;
        });

        $this->cli->expects($this->once())
            ->method('info')
            ->with('Opening Behat live view: '.Behat::VIEW_URL);

        $options = SplitbrainWrapper::suppressDeprecationWarnings(function() {
            return $this->getMockBuilder(Options::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['getOpt'])
                ->getMock();
        });

        $options->expects($this->once())
            ->method('getOpt')
            ->with('view')
            ->willReturn(true);

        $method = $this->reflection->getMethod('maybeOpenView');
        $method->invoke($this->behatCommand, $options);

        $this->assertSame(Behat::VIEW_URL, $capturedUrl);
    }

    public function testMaybeOpenViewDoesNothingWhenDisabled(): void {
        $capturedUrl = null;

        $openerProperty = $this->reflection->getProperty('viewUrlOpener');
        $openerProperty->setValue($this->behatCommand, function(string $url) use (&$capturedUrl): void {
            $capturedUrl = $url;
        });

        $this->cli->expects($this->never())->method('info');

        $options = SplitbrainWrapper::suppressDeprecationWarnings(function() {
            return $this->getMockBuilder(Options::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['getOpt'])
                ->getMock();
        });

        $options->expects($this->once())
            ->method('getOpt')
            ->with('view')
            ->willReturn(null);

        $method = $this->reflection->getMethod('maybeOpenView');
        $method->invoke($this->behatCommand, $options);

        $this->assertNull($capturedUrl);
    }

    public function testSeleniumStatusReadyReturnsTrueWhenNodeIsUp(): void {
        $statusJson = json_encode([
            'value' => [
                'nodes' => [
                    ['availability' => 'UP']
                ]
            ]
        ]);

        $method = $this->reflection->getMethod('isSeleniumStatusReady');
        $result = $method->invoke($this->behatCommand, $statusJson);

        $this->assertTrue($result);
    }

    public function testSeleniumStatusReadyReturnsFalseWhenNodeNotUp(): void {
        $statusJson = json_encode([
            'value' => [
                'nodes' => [
                    ['availability' => 'DOWN']
                ]
            ]
        ]);

        $method = $this->reflection->getMethod('isSeleniumStatusReady');
        $result = $method->invoke($this->behatCommand, $statusJson);

        $this->assertFalse($result);
    }

    public function testSeleniumStatusReadyReturnsFalseForInvalidJson(): void {
        $method = $this->reflection->getMethod('isSeleniumStatusReady');
        $result = $method->invoke($this->behatCommand, 'not-json');

        $this->assertFalse($result);
    }

    public function testEnsureSeleniumHealthyDoesNotRestartWhenAlreadyReady(): void {
        $restartCalls = 0;

        $probeProperty = $this->reflection->getProperty('seleniumReadyProbe');
        $probeProperty->setValue($this->behatCommand, function(string $moodleContainer, string $seleniumContainerName): bool {
            return true;
        });

        $restarterProperty = $this->reflection->getProperty('seleniumRestarter');
        $restarterProperty->setValue($this->behatCommand, function(string $seleniumContainerName) use (&$restartCalls): void {
            $restartCalls++;
        });

        $this->cli->expects($this->never())->method('warning');

        $method = $this->reflection->getMethod('ensureSeleniumHealthyOrRestart');
        $method->invoke($this->behatCommand, 'moodle-container', 'mchef-behat-chrome');

        $this->assertSame(0, $restartCalls);
    }

    public function testEnsureSeleniumHealthyRestartsWhenNotReadyThenRecovers(): void {
        $restartCalls = 0;
        $isRestarted = false;

        $probeProperty = $this->reflection->getProperty('seleniumReadyProbe');
        $probeProperty->setValue($this->behatCommand, function(string $moodleContainer, string $seleniumContainerName) use (&$isRestarted): bool {
            return $isRestarted;
        });

        $restarterProperty = $this->reflection->getProperty('seleniumRestarter');
        $restarterProperty->setValue($this->behatCommand, function(string $seleniumContainerName) use (&$restartCalls, &$isRestarted): void {
            $restartCalls++;
            $isRestarted = true;
        });

        $this->cli->expects($this->once())
            ->method('warning')
            ->with('Selenium health check failed. Restarting mchef-behat-chrome container...');

        $method = $this->reflection->getMethod('ensureSeleniumHealthyOrRestart');
        $method->invoke($this->behatCommand, 'moodle-container', 'mchef-behat-chrome');

        $this->assertSame(1, $restartCalls);
    }

    public function testEnsureSeleniumHealthyThrowsWhenStillNotReadyAfterRestart(): void {
        $restartCalls = 0;

        $probeProperty = $this->reflection->getProperty('seleniumReadyProbe');
        $probeProperty->setValue($this->behatCommand, function(string $moodleContainer, string $seleniumContainerName): bool {
            return false;
        });

        $restarterProperty = $this->reflection->getProperty('seleniumRestarter');
        $restarterProperty->setValue($this->behatCommand, function(string $seleniumContainerName) use (&$restartCalls): void {
            $restartCalls++;
        });

        $this->cli->expects($this->once())->method('warning');

        $method = $this->reflection->getMethod('ensureSeleniumHealthyOrRestart');

        try {
            $method->invoke($this->behatCommand, 'moodle-container', 'mchef-behat-chrome');
            $this->fail('Expected selenium readiness exception was not thrown');
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if ($e instanceof \ReflectionException) {
                $this->fail('Unexpected ReflectionException while invoking ensureSeleniumHealthyOrRestart');
            }
            if (method_exists($e, 'getPrevious') && $e->getPrevious() !== null) {
                $message = $e->getPrevious()->getMessage();
            }
            $this->assertStringContainsString('Selenium is still not ready after restart', $message);
        }

        $this->assertSame(1, $restartCalls);
    }
}
