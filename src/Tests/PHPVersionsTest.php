<?php

namespace App\Tests;

use App\Service\PHPVersions;

class TestablePHPVersions extends PHPVersions {
    protected function fetchBranchesResponse(): ?string {
        return json_encode([
            'message' => 'API rate limit exceeded for 203.0.113.1. (But here is the good news: Authenticated requests get a higher rate limit.)',
            'documentation_url' => 'https://docs.github.com/rest/overview/resources-in-the-rest-api#rate-limiting',
        ]);
    }
}

final class PHPVersionsTest extends MchefTestCase {

    public function testListVersionsFallsBackToHardcodedVersionsOnRateLimitMessage(): void {
        $reflection = new \ReflectionClass(TestablePHPVersions::class);
        $method = $reflection->getMethod('setup_singleton');
        /** @var PHPVersions $service */
        $service = $method->invoke(null, true);

        $versions = $service->listVersions();

        $this->assertSame([
            '5.6',
            '7.0',
            '7.1',
            '7.2',
            '7.3',
            '7.4',
            '8.0',
            '8.1',
            '8.2',
            '8.3',
            '8.4',
            '8.5',
        ], $versions);
    }
}
