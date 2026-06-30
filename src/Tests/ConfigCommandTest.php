<?php

namespace App\Tests;

use App\Command\Config;
use App\Helpers\SplitbrainWrapper;
use App\Service\Configurator;
use App\Traits\CallRestrictedMethodTrait;
use splitbrain\phpcli\Options;
use PHPUnit\Framework\MockObject\MockObject;

class ConfigCommandTest extends MchefTestCase {
    use CallRestrictedMethodTrait;
    private Config $configCommand;
    private MockObject $configurator;
    /** @var Options&MockObject */
    private $options;

    protected function setUp(): void {
        parent::setUp();

        $this->configurator = $this->getMockBuilder(Configurator::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setMainConfigField'])
            ->getMock();

        $this->configCommand = Config::instance(true);
        $this->applyMockedServices(['configuratorService' => $this->configurator], $this->configCommand);

        // Create options mock with more specific mocking
        $this->options = SplitbrainWrapper::suppressDeprecationWarnings(function() {
            return $this->getMockBuilder(Options::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['getOpt'])
                ->getMock();
        });
    }

    public function testSetDbClientIsDBeaver(): void {
        // Mock the options with return map
        $this->options->method('getOpt')
            ->willReturnCallback(function($opt) {
                return match($opt) {
                    'dbclient' => true,
                    'dbclient-mysql' => false,
                    'dbclient-pgsql' => false,
                    'lang' => false,
                    'proxy' => false,
                    'password' => false,
                    default => null
                };
            });

        // Mock user input to select 'dbeaver'
        $this->cli->expects($this->once())
            ->method('promptForOption')
            ->with("Select your preferred database client:")
            ->willReturn('dbeaver');

        // Expect configurator call
        $this->configurator->expects($this->once())
            ->method('setMainConfigField')
            ->with('dbClient', 'dbeaver');

        $this->cli->expects($this->once())
            ->method('notice')
            ->with('Config field \'dbClient\' has been set.', []);

        $this->configCommand->execute($this->options);
    }

    public function testSetDbClientIsPgsql(): void {
        // Mock the options with return map
        $this->options->method('getOpt')
            ->willReturnCallback(function($opt) {
                return match($opt) {
                    'dbclient' => true,
                    'dbclient-mysql' => false,
                    'dbclient-pgsql' => false,
                    'lang' => false,
                    'proxy' => false,
                    'password' => false,
                    default => null
                };
            });

        // Mock user input to select 'dbeaver'
        $this->cli->expects($this->once())
            ->method('promptForOption')
            ->with("Select your preferred database client:")
            ->willReturn('pgsql');

        // Expect configurator call
        $this->configurator->expects($this->once())
            ->method('setMainConfigField')
            ->with('dbClient', 'pgsql');

        $this->cli->expects($this->once())
            ->method('notice')
            ->with('Config field \'dbClient\' has been set.', []);

        $this->configCommand->execute($this->options);
    }

    public function testSetDbClientIsMysql(): void {
        // Mock the options with return map
        $this->options->method('getOpt')
            ->willReturnCallback(function($opt) {
                return match($opt) {
                    'dbclient' => true,
                    'dbclient-mysql' => false,
                    'dbclient-pgsql' => false,
                    'lang' => false,
                    'proxy' => false,
                    'password' => false,
                    default => null
                };
            });

        // Mock user input to select 'dbeaver'
        $this->cli->expects($this->once())
            ->method('promptForOption')
            ->with("Select your preferred database client:")
            ->willReturn('mysql');

        // Expect configurator call
        $this->configurator->expects($this->once())
            ->method('setMainConfigField')
            ->with('dbClient', 'mysql');

        $this->cli->expects($this->once())
            ->method('notice')
            ->with('Config field \'dbClient\' has been set.', []);

        $this->configCommand->execute($this->options);
    }

    public function testInvalidMysqlClientSelection(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid MySQL client option');

        $this->cli->method('promptInput')->willReturn('2');
        $this->options->method('getOpt')
            ->willReturnMap([
                ['dbclient', false],
                ['dbclient-mysql', true],
                ['dbclient-pgsql', false],
            ]);

        // Try to set 'pgadmin' as MySQL client (which is invalid)
        $this->callRestricted($this->configCommand, 'setDbClientMysql', ['pgadmin']);
    }

    public function testInvalidPgsqlClientSelection(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid PostgreSQL client option');

        $this->cli->method('promptInput')->willReturn('2');
        $this->options->method('getOpt')
            ->willReturnMap([
                ['dbclient', false],
                ['dbclient-mysql', false],
                ['dbclient-pgsql', true],
            ]);

        // Try to set 'mysql workbench' as PostgreSQL client (which is invalid)
        $this->callRestricted($this->configCommand, 'setDbClientPgsql', ['mysql workbench']);
    }

    public function testSetGithubToken(): void {
        $this->options->method('getOpt')
            ->willReturnCallback(function($opt) {
                return match($opt) {
                    'lang' => false,
                    'proxy' => false,
                    'password' => false,
                    'dbclient' => false,
                    'dbclient-mysql' => false,
                    'dbclient-pgsql' => false,
                    'registryUrl' => false,
                    'registryUsername' => false,
                    'registryPassword' => false,
                    'registryToken' => false,
                    'githubToken' => 'ghp_test_token',
                    default => null
                };
            });

        $this->configurator->expects($this->once())
            ->method('setMainConfigField')
            ->with('githubToken', 'ghp_test_token');

        $this->cli->expects($this->exactly(2))
            ->method('notice')
            ->withConsecutive(
                ['Config field \'githubToken\' has been set.', []],
                ['GitHub token has been set.', []]
            );

        $this->configCommand->execute($this->options);
    }

    public function testSetGithubUrlsRepo(): void {
        $this->options->method('getOpt')
            ->willReturnCallback(function($opt) {
                return match($opt) {
                    'lang' => false,
                    'proxy' => false,
                    'password' => false,
                    'dbclient' => false,
                    'dbclient-mysql' => false,
                    'dbclient-pgsql' => false,
                    'registryUrl' => false,
                    'registryUsername' => false,
                    'registryPassword' => false,
                    'registryToken' => false,
                    'githubToken' => false,
                    'githubUrlsRepo' => 'citricity/mchef-urls',
                    default => null
                };
            });

        $this->configurator->expects($this->once())
            ->method('setMainConfigField')
            ->with('githubUrlsRepo', 'citricity/mchef-urls');

        $this->cli->expects($this->exactly(2))
            ->method('notice')
            ->withConsecutive(
                ['Config field \'githubUrlsRepo\' has been set.', []],
                ['GitHub URLs repository has been set.', []]
            );

        $this->configCommand->execute($this->options);
    }
}
