<?php

use App\Model\Recipe;
use App\Model\RecipePlugin;
use App\Service\BlueprintConverter;
use App\Service\Github;

final class BlueprintConverterTest extends \App\Tests\MchefTestCase
{
    private BlueprintConverter $converter;

    protected function setUp(): void {
        parent::setUp();
        $this->converter = BlueprintConverter::instance(true);
    }

    public function testVersionTagConvertedToMajorMinor(): void {
        $recipe = $this->makeRecipe(['moodleTag' => 'v4.1.0']);
        $blueprint = $this->converter->convert($recipe);
        $this->assertEquals('4.1', $blueprint['preferredVersions']['moodle']);
    }

    public function testVersionTagWithoutPatchConvertedToMajorMinor(): void {
        $recipe = $this->makeRecipe(['moodleTag' => 'v5.0.0']);
        $blueprint = $this->converter->convert($recipe);
        $this->assertEquals('5.0', $blueprint['preferredVersions']['moodle']);
    }

    public function testBranchStyleTagPassedThrough(): void {
        $recipe = $this->makeRecipe(['moodleTag' => 'MOODLE_500_STABLE']);
        $blueprint = $this->converter->convert($recipe);
        $this->assertEquals('MOODLE_500_STABLE', $blueprint['preferredVersions']['moodle']);
    }

    public function testMainBranchPassedThrough(): void {
        $recipe = $this->makeRecipe(['moodleTag' => 'main']);
        $blueprint = $this->converter->convert($recipe);
        $this->assertEquals('main', $blueprint['preferredVersions']['moodle']);
    }

    public function testDevTagPassedThrough(): void {
        $recipe = $this->makeRecipe(['moodleTag' => 'dev']);
        $blueprint = $this->converter->convert($recipe);
        $this->assertEquals('dev', $blueprint['preferredVersions']['moodle']);
    }

    public function testPhpVersionPassedThrough(): void {
        $recipe = $this->makeRecipe(['phpVersion' => '8.3']);
        $blueprint = $this->converter->convert($recipe);
        $this->assertEquals('8.3', $blueprint['preferredVersions']['php']);
    }

    public function testInstallMoodleStepAlwaysPresent(): void {
        $recipe = $this->makeRecipe([]);
        $blueprint = $this->converter->convert($recipe);
        $this->assertEquals('installMoodle', $blueprint['steps'][0]['step']);
    }

    public function testAdminPasswordMapped(): void {
        $recipe = $this->makeRecipe(['adminPassword' => 'Password123!']);
        $blueprint = $this->converter->convert($recipe);
        $this->assertEquals('Password123!', $blueprint['steps'][0]['options']['adminPass']);
    }

    public function testAdminPasswordZeroMapped(): void {
        $recipe = $this->makeRecipe(['adminPassword' => '0']);
        $blueprint = $this->converter->convert($recipe);
        $this->assertEquals('0', $blueprint['steps'][0]['options']['adminPass']);
    }

    public function testAdminUserMappedWhenNonDefault(): void {
        $recipe = $this->makeRecipe([], ['admin' => 'teacher']);
        $blueprint = $this->converter->convert($recipe);
        $this->assertEquals('teacher', $blueprint['steps'][0]['options']['adminUser']);
    }

    public function testAdminUserOmittedWhenDefault(): void {
        $recipe = $this->makeRecipe(['adminPassword' => 'Password123!']);
        $blueprint = $this->converter->convert($recipe);
        $this->assertArrayHasKey('options', $blueprint['steps'][0]);
        $this->assertArrayNotHasKey('adminUser', $blueprint['steps'][0]['options']);
    }

    public function testLoginStepUsesAdminUsername(): void {
        $recipe = $this->makeRecipe([], ['admin' => 'teacher']);
        $blueprint = $this->converter->convert($recipe);
        $loginStep = $this->findStep($blueprint, 'login');
        $this->assertEquals('teacher', $loginStep['username']);
    }

    public function testLocaleAndTimezoneMapped(): void {
        $recipe = $this->makeRecipe([], ['lang' => 'en_GB', 'timezone' => 'Europe/London']);
        $blueprint = $this->converter->convert($recipe);
        $installOptions = $blueprint['steps'][0]['options'];
        $this->assertEquals('en_GB', $installOptions['locale']);
        $this->assertEquals('Europe/London', $installOptions['timezone']);
    }

    public function testInstallMoodleOptionsOmittedWhenEmpty(): void {
        $recipe = $this->makeRecipe([]);
        $blueprint = $this->converter->convert($recipe);
        $this->assertArrayNotHasKey('options', $blueprint['steps'][0]);
    }

    public function testHttpsPluginConvertedToZipUrl(): void {
        $plugin = new RecipePlugin('https://github.com/moodlehq/moodle-block_participants.git', 'master');
        $recipe = $this->makeRecipe(['plugins' => [$plugin]]);
        $blueprint = $this->converter->convert($recipe);
        $pluginStep = $this->findStep($blueprint, 'installMoodlePlugin');
        $this->assertNotNull($pluginStep);
        $this->assertEquals(
            'https://github.com/moodlehq/moodle-block_participants/archive/refs/heads/master.zip',
            $pluginStep['url']
        );
    }

    public function testSshPluginConvertedToZipUrl(): void {
        $plugin = new RecipePlugin('git@github.com:moodlehq/moodle-block_participants.git', 'main');
        $recipe = $this->makeRecipe(['plugins' => [$plugin]]);
        $blueprint = $this->converter->convert($recipe);
        $pluginStep = $this->findStep($blueprint, 'installMoodlePlugin');
        $this->assertNotNull($pluginStep);
        $this->assertEquals(
            'https://github.com/moodlehq/moodle-block_participants/archive/refs/heads/main.zip',
            $pluginStep['url']
        );
    }

    public function testStringPluginUrlConvertedWithDefaultBranch(): void {
        $recipe = $this->makeRecipe(['plugins' => ['https://github.com/moodlehq/moodle-mod_quiz.git']]);
        $blueprint = $this->converter->convert($recipe);
        $pluginStep = $this->findStep($blueprint, 'installMoodlePlugin');
        $this->assertNotNull($pluginStep);
        $this->assertStringContainsString('/archive/refs/heads/main.zip', $pluginStep['url']);
    }

    public function testStringPluginWithTildeBranchSyntax(): void {
        $recipe = $this->makeRecipe(['plugins' => ['https://github.com/moodlehq/moodle-mod_quiz.git~dev']]);
        $blueprint = $this->converter->convert($recipe);
        $pluginStep = $this->findStep($blueprint, 'installMoodlePlugin');
        $this->assertNotNull($pluginStep);
        $this->assertStringContainsString('/archive/refs/heads/dev.zip', $pluginStep['url']);
    }

    public function testNonGithubPluginSkipped(): void {
        $plugin = new RecipePlugin('https://gitlab.com/some/plugin.git', 'main');
        $recipe = $this->makeRecipe(['plugins' => [$plugin]]);
        $blueprint = $this->converter->convert($recipe);
        $this->assertNull($this->findStep($blueprint, 'installMoodlePlugin'));
    }

    public function testNonGithubPluginWarningEmitted(): void {
        $plugin = new RecipePlugin('https://gitlab.com/some/plugin.git', 'main');
        $recipe = $this->makeRecipe(['plugins' => [$plugin]]);
        $this->converter->convert($recipe);
        $warnings = $this->converter->getWarnings();
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('gitlab.com', $warnings[0]);
    }

    public function testSetThemeStepEmitted(): void {
        $recipe = $this->makeRecipe([], ['theme' => 'boost']);
        $blueprint = $this->converter->convert($recipe);
        $themeStep = $this->findStep($blueprint, 'setTheme');
        $this->assertNotNull($themeStep);
        $this->assertEquals('boost', $themeStep['name']);
    }

    public function testSetThemeStepAppearsAfterPlugins(): void {
        $plugin = new RecipePlugin('https://github.com/user/moodle-theme_snap.git', 'main');
        $recipe = $this->makeRecipe(['plugins' => [$plugin]], ['theme' => 'snap']);
        $blueprint = $this->converter->convert($recipe);

        $steps = $blueprint['steps'];
        $pluginIdx = null;
        $themeIdx  = null;
        foreach ($steps as $i => $step) {
            if ($step['step'] === 'installMoodlePlugin') $pluginIdx = $i;
            if ($step['step'] === 'setTheme')            $themeIdx  = $i;
        }
        $this->assertNotNull($pluginIdx);
        $this->assertNotNull($themeIdx);
        $this->assertGreaterThan($pluginIdx, $themeIdx);
    }

    public function testNoSetThemeWhenThemeNotSet(): void {
        $recipe = $this->makeRecipe([]);
        $blueprint = $this->converter->convert($recipe);
        $this->assertNull($this->findStep($blueprint, 'setTheme'));
    }

    public function testLandingPageMappedWhenSet(): void {
        $recipe = $this->makeRecipe(['playgroundLandingPage' => '/course/view.php?id=2']);
        $blueprint = $this->converter->convert($recipe);
        $this->assertEquals('/course/view.php?id=2', $blueprint['landingPage']);
    }

    public function testLandingPageOmittedWhenNull(): void {
        $recipe = $this->makeRecipe([]);
        $blueprint = $this->converter->convert($recipe);
        $this->assertArrayNotHasKey('landingPage', $blueprint);
    }

    public function testLandingPageValidationRejectsNonSlashPrefix(): void {
        $recipe = $this->makeRecipe(['playgroundLandingPage' => 'course/view.php?id=2']);
        $this->expectException(\InvalidArgumentException::class);
        $this->converter->convert($recipe);
    }

    public function testLandingPageIsTopLevel(): void {
        $recipe = $this->makeRecipe(['playgroundLandingPage' => '/']);
        $blueprint = $this->converter->convert($recipe);
        $this->assertArrayHasKey('landingPage', $blueprint);
        foreach ($blueprint['steps'] as $step) {
            $this->assertArrayNotHasKey('landingPage', $step);
        }
    }

    private function makeRecipe(array $recipeProps, array $configProps = []): Recipe {
        $defaults = [
            'moodleTag'   => 'v5.0.0',
            'phpVersion'  => '8.3',
        ];
        $data = array_merge($defaults, $recipeProps);

        $recipe = Recipe::fromJSON(json_encode($data));

        foreach ($configProps as $key => $value) {
            $recipe->config->$key = $value;
        }

        return $recipe;
    }

    private function findStep(array $blueprint, string $stepName): ?array {
        foreach ($blueprint['steps'] as $step) {
            if ($step['step'] === $stepName) {
                return $step;
            }
        }
        return null;
    }
}
