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

    public function testRenderRedirectHtmlUsesGithubTemplate(): void {
        $main = $this->getMockBuilder(Main::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getTwig'])
            ->getMock();

        $loader = new \Twig\Loader\FilesystemLoader(dirname(__DIR__, 2) . '/templates');
        $loader->addPath(dirname(__DIR__, 2) . '/templates/github', 'github');
        $twig = new \Twig\Environment($loader);

        $main->method('getTwig')->willReturn($twig);

        $this->setRestrictedProperty($this->service, 'mainService', $main);

        $html = $this->service->renderRedirectHtml('https://example.com/path?x=1&y=2');

        $this->assertStringContainsString('<meta http-equiv="refresh"', $html);
        $this->assertStringContainsString('url=https://example.com/path?x=1&amp;y=2', $html);
        $this->assertStringContainsString('location.replace("https://example.com/path?x=1&y=2")', $html);
        $this->assertStringContainsString('location.replace', $html);
    }

    public function testPublishRedirectUrlPublishesHtmlAndReturnsUrls(): void {
        $main = $this->getMockBuilder(Main::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getTwig'])
            ->getMock();

        $loader = new \Twig\Loader\FilesystemLoader(dirname(__DIR__, 2) . '/templates');
        $loader->addPath(dirname(__DIR__, 2) . '/templates/github', 'github');
        $twig = new \Twig\Environment($loader);
        $main->method('getTwig')->willReturn($twig);

        $github = $this->createMock(Github::class);
        $github->expects($this->once())
            ->method('publishHtmlToRepository')
            ->with(
                'citricity/mchef-urls',
                'ABCD1234.html',
                $this->stringContains('location.replace("https://example.com/really/long/url?with=lots&of=query&params=true")'),
                'ghs_test',
                'ABCD1234',
                'main'
            )
            ->willReturn('https://github.com/citricity/mchef-urls/blob/main/ABCD1234.html');

        $github->expects($this->once())
            ->method('buildGithubPagesUrl')
            ->with('citricity/mchef-urls', 'ABCD1234.html')
            ->willReturn('https://citricity.github.io/mchef-urls/ABCD1234.html');

        $this->setRestrictedProperty($this->service, 'mainService', $main);
        $this->setRestrictedProperty($this->service, 'githubService', $github);

        $result = $this->service->publishRedirectUrl(
            'https://example.com/really/long/url?with=lots&of=query&params=true',
            'citricity/mchef-urls',
            'ghs_test',
            'ABCD1234'
        );

        $this->assertEquals('ABCD1234', $result['id']);
        $this->assertEquals('https://github.com/citricity/mchef-urls/blob/main/ABCD1234.html', $result['resourceUrl']);
        $this->assertEquals('https://citricity.github.io/mchef-urls/ABCD1234.html', $result['shortUrl']);
    }
}
