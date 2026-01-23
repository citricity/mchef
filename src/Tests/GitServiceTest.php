<?php

namespace App\Tests;

use App\Service\Git;

final class GitServiceTest extends MchefTestCase {
    public function testBranchOrTagExistsRemotely() {
        $url = 'https://github.com/gthomas2/moodle-filter_imageopt.git';
        $branch = 'master';
        $exists = Git::instance()->branchOrTagExistsRemotely($url, $branch);
        $this->assertTrue($exists);
    }
}