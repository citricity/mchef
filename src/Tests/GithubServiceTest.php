<?php

namespace App\Tests;

use App\Exceptions\CliRuntimeException;
use App\Service\Github;

final class GithubServiceTest extends MchefTestCase {

    public function testPublishHtmlToRepositoryReturnsHtmlUrlFromApiResponse(): void {
        $github = new class(201, [
            'content' => [
                'html_url' => 'https://github.com/citricity/mchef-urls/blob/main/ABCD1234.html',
                'download_url' => 'https://raw.githubusercontent.com/citricity/mchef-urls/main/ABCD1234.html',
            ],
        ]) extends Github {
            public function __construct(private int $status, private array $json) {}
            protected function putRepoContents(string $repo, string $path, string $token, array $payload): array {
                return ['status' => $this->status, 'body' => json_encode($this->json)];
            }
        };

        $url = $github->publishHtmlToRepository(
            'citricity/mchef-urls',
            'ABCD1234.html',
            '<html>ok</html>',
            'ghs_test',
            'ABCD1234'
        );

        $this->assertEquals('https://github.com/citricity/mchef-urls/blob/main/ABCD1234.html', $url);
    }

    public function testPublishHtmlToRepositoryFallsBackToBlobUrlWhenApiDoesNotReturnUrls(): void {
        $github = new class(201, ['content' => []]) extends Github {
            public function __construct(private int $status, private array $json) {}
            protected function putRepoContents(string $repo, string $path, string $token, array $payload): array {
                return ['status' => $this->status, 'body' => json_encode($this->json)];
            }
        };

        $url = $github->publishHtmlToRepository(
            'citricity/mchef-urls',
            'EFGH5678.html',
            '<html>ok</html>',
            'ghs_test',
            'EFGH5678'
        );

        $this->assertEquals('https://github.com/citricity/mchef-urls/blob/main/EFGH5678.html', $url);
    }

    public function testPublishHtmlToRepositoryThrowsOnHttpFailure(): void {
        $github = new class(401, ['message' => 'Bad credentials']) extends Github {
            public function __construct(private int $status, private array $json) {}
            protected function putRepoContents(string $repo, string $path, string $token, array $payload): array {
                return ['status' => $this->status, 'body' => json_encode($this->json)];
            }
        };

        $this->expectException(CliRuntimeException::class);
        $this->expectExceptionMessage('GitHub publish failed with HTTP 401');

        $github->publishHtmlToRepository(
            'citricity/mchef-urls',
            'IJKL9012.html',
            '<html>ok</html>',
            'ghs_test',
            'IJKL9012'
        );
    }

    public function testBuildGithubPagesUrlForProjectAndUserPagesRepos(): void {
        $github = Github::instance();

        $projectPages = $github->buildGithubPagesUrl('citricity/mchef-urls', 'ABCD1234.html');
        $userPages = $github->buildGithubPagesUrl('citricity/citricity.github.io', 'ABCD1234.html');

        $this->assertEquals('https://citricity.github.io/mchef-urls/ABCD1234.html', $projectPages);
        $this->assertEquals('https://citricity.github.io/ABCD1234.html', $userPages);
    }
}
