<?php

namespace App\Command;

use App\Service\Configurator;
use App\Traits\ExecTrait;
use App\Traits\SingletonTrait;
use splitbrain\phpcli\Options;

final class Config extends AbstractCommand {

    use SingletonTrait;
    use ExecTrait;

    const COMMAND_NAME = 'config';

    public static function instance(bool $reset = false): Config {
        return self::setup_singleton($reset);
    }

    public const DB_CLIENT_OPTIONS = [
        'dbeaver',
        'pgadmin',
        'mysql workbench',
        'psql (cli)',
        'mysql (cli)'
    ];

    public const DB_CLIENT_MYSQL_OPTIONS = [
        'dbeaver',
        'mysql workbench',
        'mysql (cli)'
    ];

    public const DB_CLIENT_PGSQL_OPTIONS = [
        'dbeaver',
        'pgadmin',
        'psql (cli)'
    ];

    private function validateLang(string $langCode) {
        $moodleLangCodes = [
            'af', 'am', 'ar', 'az', 'be', 'bg', 'bn', 'bs', 'ca', 'cs', 'cy', 'da', 'de',
            'el', 'en', 'en_ar', 'en_au', 'en_ca', 'en_nz', 'en_us', 'en_ww', 'eo', 'es',
            'es_ar', 'es_co', 'es_cr', 'es_mx', 'es_pe', 'es_ve', 'et', 'eu', 'fa', 'fi',
            'fo', 'fr', 'fr_ca', 'ga', 'gd', 'gl', 'gu', 'he', 'hi', 'hr', 'hu', 'hy',
            'id', 'is', 'it', 'ja', 'jv', 'ka', 'kk', 'km', 'kn', 'ko', 'ku', 'ky', 'la',
            'lt', 'lv', 'mk', 'ml', 'mn', 'mr', 'ms', 'mt', 'my', 'nb', 'ne', 'nl', 'nn',
            'no', 'oc', 'or', 'pa', 'pl', 'ps', 'pt', 'pt_br', 'qu', 'ro', 'ru', 'rw',
            'si', 'sk', 'sl', 'so', 'sq', 'sr', 'sr_lt', 'sv', 'sw', 'ta', 'te', 'th',
            'tl', 'tr', 'ug', 'uk', 'ur', 'uz', 'vi', 'wo', 'zh_cn', 'zh_hk', 'zh_tw', 'zu'
        ];
        if (!in_array($langCode, $moodleLangCodes)) {
            $this->cli->warning('Potentially invalid lang code selected. Lang codes should be two char or [xx_xx] for sub lang');
        }
    }

    private function setLang(string $langCode) {
        $this->validateLang($langCode);
        $this->configuratorService->setMainConfigField('lang', $langCode);
        $this->cli->notice("Default language code has been set. Note - will only affect new installs");
    }

    private function setPassword(string $password) {
        $this->configuratorService->setMainConfigField('adminPassword', $password);
        $this->cli->notice("Default admin password has been set. Note - will only affect new installs");
    }

    private function setProxy(bool $proxy) {
        $this->configuratorService->setMainConfigField('useProxy', $proxy);
        $this->cli->notice("Local reverse proxy settings changed.\n".
            "NOTE: You will need to stop all your mchef instances and re-up them to use the new settings");
    }

    public function execute(Options $options): void {
        if (!empty($options->getOpt('lang'))) {
            $this->setLang($options->getOpt('lang'));
        } else if (!empty($options->getOpt('proxy'))) {
            $this->cli->promptYesNo("Enable local reverse proxy?\n".
                "(This will make all your containers accessible on port 80.)",
                onYes: fn() => $this->setProxy(true),
                onNo: fn() => $this->setProxy(false));
        } else if (!empty($options->getOpt('password'))) {
            $password = $this->cli->promptInput('Please enter a password: ');
            $this->setPassword($password);
        } else if (!empty($options->getOpt('dbclient'))) {
            $client = $this->cli->promptForOption('Select your preferred database client:', self::DB_CLIENT_OPTIONS);
            $this->setDbClient(trim($client));
        } else if (!empty($options->getOpt('dbclient-mysql'))) {
            $client = $this->cli->promptForOption('Select your preferred MySQL client:', self::DB_CLIENT_MYSQL_OPTIONS);
            $this->setDbClientMysql(trim($client));
        } else if (!empty($options->getOpt('dbclient-pgsql'))) {
            $client = $this->cli->promptForOption('Select your preferred PostgreSQL client:', self::DB_CLIENT_PGSQL_OPTIONS);
            $this->setDbClientPgsql(trim($client));
        } else if (!empty($options->getOpt('registryUrl'))) {
            $this->setRegistryUrl(trim($options->getOpt('registryUrl')));
        } else if (!empty($options->getOpt('registryUsername'))) {
            $this->setRegistryUsername(trim($options->getOpt('registryUsername')));
        } else if (!empty($options->getOpt('registryPassword'))) {
            $this->setRegistryPassword(trim($options->getOpt('registryPassword')));
        } else if (!empty($options->getOpt('registryToken'))) {
            $this->setRegistryToken(trim($options->getOpt('registryToken')));
        } else if ($options->getOpt('get-config-dir')) {
            $configDir = $this->configuratorService->configDir();
            $this->cli->notice("Config directory: $configDir");
        } else {
            $this->cli->error('Invalid config option');
        }
    }

    private function setDbClient(string $client) {
        $this->configuratorService->setMainConfigField('dbClient', $client);
        $this->cli->notice("Default database client has been set.");
    }

    private function setDbClientMysql(string $client) {
        if (!in_array($client, self::DB_CLIENT_MYSQL_OPTIONS)) {
            throw new \InvalidArgumentException("Invalid MySQL client option");
        }
        $this->configuratorService->setMainConfigField('dbClientMysql', $client);
        $this->cli->notice("Default MySQL client has been set.");
    }

    private function setDbClientPgsql(string $client) {
        if (!in_array($client, self::DB_CLIENT_PGSQL_OPTIONS)) {
            throw new \InvalidArgumentException("Invalid PostgreSQL client option");
        }
        $this->configuratorService->setMainConfigField('dbClientPgsql', $client);
        $this->cli->notice("Default PostgreSQL client has been set.");
    }

    private function setRegistryUrl(string $url) {
        $this->configuratorService->setMainConfigField('registryUrl', $url);
        $this->cli->notice("Docker image registry URL has been set.");
    }

    private function setRegistryUsername(string $username) {
        $this->configuratorService->setMainConfigField('registryUsername', $username);
        $this->cli->notice("Docker image registry username has been set.");
    }

    private function setRegistryPassword(string $password) {
        $this->configuratorService->setMainConfigField('registryPassword', $password);
        $this->cli->notice("Docker image registry password has been set.");
    }

    private function setRegistryToken(string $token) {
        $this->configuratorService->setMainConfigField('registryToken', $token);
        $this->cli->notice("Docker image registry token has been set.");
    }

    protected function register(Options $options): void {
        $options->registerCommand(self::COMMAND_NAME, 'Configure mchef globally');
        $options->registerOption('lang', 'Set a default language code', 'l', true, self::COMMAND_NAME);
        $options->registerOption('password', 'Set a default admin password', 'a', false, self::COMMAND_NAME);
        $options->registerOption('proxy', 'Proxy all sites so that they run on 80', 'p', false, self::COMMAND_NAME);
        $options->registerOption('dbclient', 'Set the default database client', null, false, self::COMMAND_NAME);
        $options->registerOption('dbclient-mysql', 'Set the default MySQL client', null, false, self::COMMAND_NAME);
        $options->registerOption('dbclient-pgsql', 'Set the default PostgreSQL client', null, false, self::COMMAND_NAME);
        $options->registerOption('registryUrl', 'Set docker image registry url', null, 'URL', self::COMMAND_NAME);
        $options->registerOption('registryUsername', 'Set docker image registry username', null, 'USERNAME', self::COMMAND_NAME);
        $options->registerOption('registryPassword', 'Set docker image registry password', null, 'PASSWORD', self::COMMAND_NAME);
        $options->registerOption('registryToken', 'Set docker image registry token', null, 'TOKEN', self::COMMAND_NAME);
        $options->registerOption('get-config-dir', 'Get the path to the config directory', null, false, self::COMMAND_NAME);
    }
}
