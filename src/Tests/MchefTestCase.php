<?php

namespace App\Tests;

use App\Helpers\SplitbrainWrapper;
use App\Helpers\Testing;
use App\Helpers\TestingHelpers;
use App\Interfaces\SingletonInterface;
use App\MChefCLI;
use App\StaticVars;
use PHPUnit\Framework\MockObject\MockObject;

class MchefTestCase extends \PHPUnit\Framework\TestCase {
    protected MockObject $cli;
    protected function setUp(): void {
        parent::setUp();
        
        TestingHelpers::setIsPHPunit(true);
        
        SplitbrainWrapper::suppressDeprecationWarnings(function() {
            $mockCli = $this->createMock(\App\MChefCLI::class);
            $this->cli = $mockCli;
            StaticVars::$cli = $mockCli;
        });

        // Create terms agreement for tests by default
        $termsService = \App\Service\TermsService::instance($this->cli);
        $termsService->createTermsAgreementForTesting();
    }

    protected function tearDown(): void {
        TestingHelpers::deleteTestDir();
        parent::tearDown();
    }

    protected function setRestrictedProperty(object $object, string $propertyName, mixed $value): void {
        Testing::setRestrictedProperty($object, $propertyName, $value);
    }

    protected function applyMockedServices(array $services, SingletonInterface $object): void {
        foreach ($services as $propName => $service) {
            $this->setRestrictedProperty($object, $propName, $service);
        }
    }
}
