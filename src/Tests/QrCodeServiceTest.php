<?php

namespace App\Tests;

use App\Service\Github;
use App\Service\Main;
use App\Service\QrCodeService;

final class QrCodeServiceTest extends MchefTestCase {

    private QrCodeService $service;

    protected function setUp(): void {
        parent::setUp();
        $this->service = QrCodeService::instance();
    }

    public function testPublishRedirectUrlPublishesHtmlAndReturnsUrls(): void {
        $main = $this->getMockBuilder(Main::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getTwig'])
            ->getMock();

        $github = $this->createMock(Github::class);
        $testUrl = 'https://example.com/really/long/url?with=lots&of=query&params=true';
        $shaTestUrl = sha1($testUrl);
        $github->expects($this->once())
            ->method('publishUrlToRepository')
            ->with(
                'citricity/mchef-urls',
                'links/'.$shaTestUrl.'.txt',
                $testUrl,
                'ghs_test',
                $shaTestUrl,
                'main'
            )
            ->willReturn('https://github.com/citricity/mchef-urls/blob/main/links/'.$shaTestUrl.'.txt');

        $github->expects($this->once())
            ->method('buildGithubPagesUrl')
            ->with('citricity/mchef-urls', $shaTestUrl)
            ->willReturn('https://citricity.github.io/mchef-urls/index.html?linkHash='.$shaTestUrl);

        $this->setRestrictedProperty($this->service, 'mainService', $main);
        $this->setRestrictedProperty($this->service, 'githubService', $github);

        $result = $this->service->publishRedirectUrl(
            $testUrl,
            'citricity/mchef-urls',
            'ghs_test'
        );

        $this->assertEquals($shaTestUrl, $result['id']);
        $this->assertEquals('https://github.com/citricity/mchef-urls/blob/main/links/'.$shaTestUrl.'.txt', $result['resourceUrl']);
        $this->assertEquals('https://citricity.github.io/mchef-urls/index.html?linkHash='.$shaTestUrl, $result['shortUrl']);
    }
}
