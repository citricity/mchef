<?php

namespace App\Model;

use App\Attributes\ArrayOf;

class Recipe extends AbstractModel {
    private string $_recipePath;

    public function setRecipePath(string $recipePath) {
        $this->_recipePath = $recipePath;
    }
    public function getRecipePath(): string {
        return $this->_recipePath;
    }

    public function __construct(

        /**
         * @var string - e.g. 4.1.0
         */
        public string $moodleTag,

        /**
         * @var string - e.g. 8.0
         */
        public string $phpVersion,

        /**
         * @var string - recipe name NOTE: THIS IS A UNIQUE IDENTIFIER FOR YOUR RECIPE.
         */
        public ?string $name = null,

        /**
         * @var string - recipe version.
         */
        public ?string $version = null,

        /**
         * @var string - recipe vendor name.
         */
        public ?string $vendor = null,

        /**
         * @var array
         */
        #[ArrayOf('string', RecipePlugin::class)]
        public ?array $plugins = null,

        /**
         * @var bool - if true, clone repository plugins to cwd/plugins
         * @deprecated Use mountPlugins instead
         */
        public ?bool $cloneRepoPlugins = null,

        /**
         * @var bool - if true, use volume mounts for plugins (facilitates local development)
         */
        public ?bool $mountPlugins = null,

        /**
         * @var string - web host (leave blank for default of localhost).
         */
        public ?string $host = null,

        /**
         * @var string - http or https.
         */
        public string $hostProtocol = 'http',

        /**
         * @var int - web port (leave blank for default of 80).
         */
        public ?int $port = null,

        /**
         * @var bool - set to true to add "host" to /etc/hosts if not present.
         */
        public ?bool $updateHostHosts = null,

        /**
         * @var int - maximum upload size via php.
         */
        public ?int $maxUploadSize = null,

        /**
         * @var int - maximum execution time.
         */
        public ?int $maxExecTime = null,

        /**
         * @var string - database type - e.g. pgsql / mysql
         */
        public ?string $dbType = 'pgsql',

        /**
         * @var string - database version - e.g 8
         */
        public ?string $dbVersion = null,

        /**
         * @var string - database user
         */
        public string $dbUser = 'moodle',

        /**
         * @var string - database password
         */
        public string $dbPassword = 'm@0dl3ing',

        /**
         * @var string - database root password
         */
        public ?string $dbRootPassword = null,

        /**
         * @var string|null - database name
         */
        public ?string $dbName = null,

        /**
         * @var string|null - database host port to forward to
         */
        public ?string $dbHostPort = null,

        /**
         * @var boolean|null - wheter to install Moodle or not directly
         */
        public bool $installMoodledb = true,

        /**
         * Prefix for containers.
         * Default is 'mc'.
         * Setting this to 'fred' would result in the following containers:
         * fred-db
         * fred-moodle
         * fred-behat
         * @var string|null
         */
        public ?string $containerPrefix = 'mc',

        /**
         * @var bool - debug
         */
        public bool $debug = false,

        /**
         * @var bool - developer mode (installs more tools to docker image).
         */
        public bool $developer = false,

        /**
         * @var bool - include php unit configuration
         */
        public bool $includePhpUnit = false,

        /**
         * @var bool - include behat configuration
         */
        public bool $includeBehat = false,

        /**
         * @var bool - include xdebug
         */
        public bool $includeXdebug = false,

        /**
         * @var string - xdebug mode
         */
        public $xdebugMode = null,

        /**
         * @var string - behat host
         */
        public ?string $behatHost = null,

        /**
         * @var string|null - default admin password
         */
        public ?string $adminPassword = null,

        /**
         * @var string|null - prefix for published Docker image tags (e.g. "my-app" results in "my-app:v1.5.0")
         */
        public ?string $publishTagPrefix = null,

        /**
         * @var string - directory name for moodle source and plugins (default: 'moodle')
         */
        public ?string $moodleDirectory = 'moodle',

        // The following properties are set automatically on parse.

        public ?string $wwwRoot = null,

        public ?string $behatWwwRoot = null,

        public ?string $lang = null,

        public MoodleConfig $config = new MoodleConfig(),

        /**
         * @var SampleData|null - Sample data configuration for test data generation
         */
        public ?SampleData $sampleData = null,

        // These properties are used to build the custom config file.

        public ?string $configFile = null,

        public ?string $customConfigFile = null,

        public bool $copyCustomConfigFile = false,

        public string $UNSET = self::UNSET,

    ) {}
}
