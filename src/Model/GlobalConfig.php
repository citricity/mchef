<?php

namespace App\Model;

class GlobalConfig extends AbstractModel {

    public function __construct(
        /**
         * Proxy the site so that it can be accessed on port 80 locally.
         * @var bool
         */
        public ?bool $useProxy = false,

        /**
         * Defaults the language for installs to this lang
         * @var string
         */
        public ?string $lang = 'en',

        /**
         * The name of the default instance you are currently using or null / empty for none selected
         * @var string|null
         */
        public ?string $instance = null,

        /**
         * The default admin password for all recipes that don't define one.
         * @var string|null
         */
        public ?string $adminPassword = null,

        /**
         * Selected database client for opening DBs
         * @var string|null
         */
        public ?string $dbClient = null,

        /**
         * Selected MySQL client for opening DBs
         * @var string|null
         */
        public ?string $dbClientMysql = null,

        /**
         * Selected PostgreSQL client for opening DBs
         * @var string|null
         */
        public ?string $dbClientPgsql = null,


        /**
         * Registry URL for publishing images
         */
        public ?string $registryUrl = null,

        /**
         * Registry username for publishing images
         */
        public ?string $registryUsername = null,

        /**
         * Registry token for publishing images
         */
        public ?string $registryToken = null,

        /**
         * Registry password for publishing images
         */
        public ?string $registryPassword = null
    ) {}
}
