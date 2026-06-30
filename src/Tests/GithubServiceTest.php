<?php


use App\Service\Github;

final class GithubServiceTest extends \App\Tests\MchefTestCase
{
    public function testGithubToDownloadZipUrl() {
        $urlhttp = 'https://github.com/moodle/moodle.git';
        $urlssh = 'git@github.com:moodle/moodle.git';
        $branch = 'master';
        $expected = 'https://github.com/moodle/moodle/archive/refs/heads/master.zip';
        $actual = Github::instance()->githubToDownloadZipUrl($urlhttp, $branch);
        $this->assertEquals($expected, $actual);
        $actual = Github::instance()->githubToDownloadZipUrl($urlssh, $branch);
        $this->assertEquals($expected, $actual);
    }
}