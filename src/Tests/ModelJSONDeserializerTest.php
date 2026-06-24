<?php

namespace App\Tests;

use App\Enums\DebugMode;
use App\Model\GlobalConfig;
use App\Model\Recipe;
use App\Service\ModelJSONDeserializer;
use PHPUnit\Framework\TestCase;

final class ModelJSONDeserializerTest extends MchefTestCase {

    public function testDeserializeSimpleRecipe(): void {
        $json = '{
            "moodleTag": "v4.1.0",
            "phpVersion": "8.0",
            "name": "test-recipe",
            "plugins": ["https://github.com/user/plugin1.git", "https://github.com/user/plugin2.git"]
        }';

        $deserializer = ModelJSONDeserializer::instance();
        $recipe = $deserializer->deserialize($json, Recipe::class);

        $this->assertInstanceOf(Recipe::class, $recipe);
        $this->assertEquals('v4.1.0', $recipe->moodleTag);
        $this->assertEquals('8.0', $recipe->phpVersion);
        $this->assertEquals('test-recipe', $recipe->name);
        $this->assertIsArray($recipe->plugins);
        $this->assertCount(2, $recipe->plugins);
        $this->assertEquals('https://github.com/user/plugin1.git', $recipe->plugins[0]);
        $this->assertEquals('https://github.com/user/plugin2.git', $recipe->plugins[1]);
    }

    public function testDeserializeRecipeWithDefaults(): void {
        $json = '{
            "moodleTag": "v4.1.0",
            "phpVersion": "8.0"
        }';

        $deserializer = ModelJSONDeserializer::instance();
        $recipe = $deserializer->deserialize($json, Recipe::class);

        $this->assertInstanceOf(Recipe::class, $recipe);
        $this->assertEquals('v4.1.0', $recipe->moodleTag);
        $this->assertEquals('8.0', $recipe->phpVersion);
        $this->assertNull($recipe->name);
        $this->assertNull($recipe->plugins);
        $this->assertNull($recipe->cloneRepoPlugins); // Deprecated field.
        $this->assertNull($recipe->mountPlugins);
        $this->assertEquals('pgsql', $recipe->dbType);
    }

    public function testDeserializeRecipeWithAllProperties(): void {
        $json = '{
            "moodleTag": "v4.1.0",
            "phpVersion": "8.0",
            "name": "full-recipe",
            "version": "1.0.0",
            "vendor": "test-vendor",
            "plugins": [
                "https://github.com/user/plugin1.git",
                "https://github.com/user/plugin2.git"
            ],
            "cloneRepoPlugins": true,
            "host": "example.test",
            "hostProtocol": "https",
            "port": 443,
            "updateHostHosts": true,
            "maxUploadSize": 100,
            "maxExecTime": 300,
            "dbType": "mysql",
            "dbVersion": "8.0",
            "dbUser": "testuser",
            "dbPassword": "testpass",
            "containerPrefix": "test"
        }';

        $deserializer = ModelJSONDeserializer::instance();
        $recipe = $deserializer->deserialize($json, Recipe::class);

        $this->assertInstanceOf(Recipe::class, $recipe);
        $this->assertEquals('v4.1.0', $recipe->moodleTag);
        $this->assertEquals('8.0', $recipe->phpVersion);
        $this->assertEquals('full-recipe', $recipe->name);
        $this->assertEquals('1.0.0', $recipe->version);
        $this->assertEquals('test-vendor', $recipe->vendor);
        $this->assertTrue($recipe->cloneRepoPlugins);
        $this->assertEquals('example.test', $recipe->host);
        $this->assertEquals('https', $recipe->hostProtocol);
        $this->assertEquals(443, $recipe->port);
        $this->assertTrue($recipe->updateHostHosts);
        $this->assertEquals(100, $recipe->maxUploadSize);
        $this->assertEquals(300, $recipe->maxExecTime);
        $this->assertEquals('mysql', $recipe->dbType);
        $this->assertEquals('8.0', $recipe->dbVersion);
        $this->assertEquals('testuser', $recipe->dbUser);
        $this->assertEquals('testpass', $recipe->dbPassword);
        $this->assertEquals('test', $recipe->containerPrefix);

        // Test plugins array
        $this->assertIsArray($recipe->plugins);
        $this->assertCount(2, $recipe->plugins);
        $this->assertEquals('https://github.com/user/plugin1.git', $recipe->plugins[0]);
        $this->assertEquals('https://github.com/user/plugin2.git', $recipe->plugins[1]);
    }

    public function testDeserializeRecipeWithEmptyPluginsArray(): void {
        $json = '{
            "moodleTag": "v4.1.0",
            "phpVersion": "8.0",
            "plugins": []
        }';

        $deserializer = ModelJSONDeserializer::instance();
        $recipe = $deserializer->deserialize($json, Recipe::class);

        $this->assertInstanceOf(Recipe::class, $recipe);
        $this->assertIsArray($recipe->plugins);
        $this->assertCount(0, $recipe->plugins);
    }

    public function testDeserializeFromJSONStaticMethod(): void {
        $json = '{
            "moodleTag": "v4.1.0",
            "phpVersion": "8.0",
            "name": "static-test",
            "plugins": ["plugin1", "plugin2", "plugin3"]
        }';

        $recipe = Recipe::fromJSON($json);

        $this->assertInstanceOf(Recipe::class, $recipe);
        $this->assertEquals('v4.1.0', $recipe->moodleTag);
        $this->assertEquals('8.0', $recipe->phpVersion);
        $this->assertEquals('static-test', $recipe->name);
        $this->assertIsArray($recipe->plugins);
        $this->assertCount(3, $recipe->plugins);
        $this->assertEquals('plugin1', $recipe->plugins[0]);
        $this->assertEquals('plugin2', $recipe->plugins[1]);
        $this->assertEquals('plugin3', $recipe->plugins[2]);
    }

    public function testInvalidJSON(): void {
        $invalidJson = '{invalid json}';

        $this->expectException(\splitbrain\phpcli\Exception::class);
        $this->expectExceptionMessage('Invalid JSON');

        $deserializer = ModelJSONDeserializer::instance();
        $deserializer->deserialize($invalidJson, Recipe::class);
    }

    public function testMissingRequiredParameter(): void {
        $json = '{
            "phpVersion": "8.0"
        }';

        $this->expectException(\splitbrain\phpcli\Exception::class);
        $this->expectExceptionMessage("Required parameter 'moodleTag' missing");

        $deserializer = ModelJSONDeserializer::instance();
        $deserializer->deserialize($json, Recipe::class);
    }

    public function testNonExistentModelClass(): void {
        $json = '{"test": "value"}';

        $this->expectException(\splitbrain\phpcli\Exception::class);
        $this->expectExceptionMessage('Model class does not exist');

        $deserializer = ModelJSONDeserializer::instance();
        $deserializer->deserialize($json, 'NonExistentClass');
    }

    public function testDeserializeRecipeWithPluginModels(): void {
        $json = '{
            "moodleTag": "v4.1.0",
            "phpVersion": "8.0",
            "name": "plugin-models-test",
            "plugins": [
                "https://github.com/user/simple-plugin.git",
                {
                    "repo": "https://github.com/gthomas2/moodle-filter_imageopt",
                    "branch": "main",
                    "upstream": "https://github.com/upstream/moodle-filter_imageopt"
                }
            ]
        }';

        $deserializer = ModelJSONDeserializer::instance();
        $recipe = $deserializer->deserialize($json, Recipe::class);

        $this->assertInstanceOf(Recipe::class, $recipe);
        $this->assertEquals('v4.1.0', $recipe->moodleTag);
        $this->assertEquals('8.0', $recipe->phpVersion);
        $this->assertEquals('plugin-models-test', $recipe->name);

        // Test plugins array with mixed types
        $this->assertIsArray($recipe->plugins);
        $this->assertCount(2, $recipe->plugins);

        // First plugin should be a string
        $this->assertIsString($recipe->plugins[0]);
        $this->assertEquals('https://github.com/user/simple-plugin.git', $recipe->plugins[0]);

        // Second plugin should be a RecipePlugin model
        $this->assertInstanceOf(\App\Model\RecipePlugin::class, $recipe->plugins[1]);
        $this->assertEquals('https://github.com/gthomas2/moodle-filter_imageopt', $recipe->plugins[1]->repo);
        $this->assertEquals('main', $recipe->plugins[1]->branch);
        $this->assertEquals('https://github.com/upstream/moodle-filter_imageopt', $recipe->plugins[1]->upstream);
    }

    public function testDeserializeGlobalConfigWithValidEnumValue(): void {
        $json = '{
            "debugMode": "verbose"
        }';

        $deserializer = ModelJSONDeserializer::instance();
        $config = $deserializer->deserialize($json, GlobalConfig::class);

        $this->assertInstanceOf(GlobalConfig::class, $config);
        $this->assertSame(DebugMode::VERBOSE, $config->debugMode);
    }

    public function testDeserializeGlobalConfigWithInvalidEnumValueFallsBackToDefault(): void {
        $json = '{
            "debugMode": "not-a-real-mode"
        }';

        $deserializer = ModelJSONDeserializer::instance();
        $config = $deserializer->deserialize($json, GlobalConfig::class);

        $this->assertInstanceOf(GlobalConfig::class, $config);
        $this->assertSame(DebugMode::NONE, $config->debugMode);
    }
}
