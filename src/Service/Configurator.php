<?php

namespace App\Service;
use App\Helpers\OS;
use App\Helpers\TestingHelpers;
use App\Model\GlobalConfig;
use App\Model\RegistryInstance;

class Configurator extends AbstractService {

    static $config = null;

    private function invalidateCachedMainConfig(): void {
        static::$config = null;
    }

    final public static function instance(bool $reset = false): Configurator {
        return self::setup_singleton($reset)->initializeConfig();
    }

    protected function initializeConfig(): Configurator {
        $this->establishConfigDir();
        return $this;
    }

    public function configDir(): string {
        if (TestingHelpers::isPHPUnit()) {
            return OS::realPath(sys_get_temp_dir()).'/mchef_test_config';
        }
        // Note can't realPath both because mchef dir might not exist.
        return OS::realPath('~').OS::path('/.config/mchef');
    }

    private function mainConfigPath(): string {
        return OS::path($this->configDir().'/main.json');
    }

    private function createDirIfNotExists(string $dir, string $onErrorMsg): void {
        try {
            if (!file_exists($dir)) {
                mkdir($dir, 0755, true);
            }
        } catch (\Exception $e) {
            throw new \Error($onErrorMsg.': '.$dir, 0, $e);
        }
    }

    private function establishConfigDir(): void {
        $this->createDirIfNotExists($this->configDir(), 'Failed to create config dir');
    }

    private function getRegistryFilePath(): string {
        return OS::path($this->configDir().'/registry.txt');
    }

    private function serialzeRegistryInstance(RegistryInstance $instance) {
        $port = $instance->proxyModePort ?? '';
        return "$instance->uuid|$instance->recipePath|$instance->containerPrefix|$port";
    }

    private function deserializeRegistryInstance(string $instanceRow): ?RegistryInstance {
        $tmparr = explode("|", $instanceRow);
        if (count($tmparr) < 3 || count($tmparr) > 4) {
            // Support both old format (3 elements) and new format (4 elements)
            $this->cli->warning('Invalid instance in registry'. $instanceRow);
            return null;
        }

        $proxyModePort = null;
        if (count($tmparr) === 4 && !empty($tmparr[3])) {
            $proxyModePort = (int)$tmparr[3];
        }

        return new RegistryInstance($tmparr[0], $tmparr[1], $tmparr[2], $proxyModePort);
    }

    /**
     * @return RegistryInstance[] - hashed by uuid
     */
    public function getInstanceRegistry(): array {
        $path = $this->getRegistryFilePath();
        if (!file_exists($this->configDir())) {
            $this->establishConfigDir();
        }
        if (!file_exists($path)) {
            touch($path);
        }
        $instances = [];
        $contents = file_get_contents($path);
        if (!empty(trim($contents))) {
            $rows = explode("\n", $contents);
            foreach ($rows as $row) {
                $instance = $this->deserializeRegistryInstance($row);
                if (!$instance) {
                    continue;
                }
                $instances[$instance->uuid] = $instance;
            }
        }
        return $instances;
    }

    public function getRegisteredInstance(string $instanceName): ?RegistryInstance {
        $instances = $this->getInstanceRegistry();
        $default = $this->getMainConfig()->instance;
        foreach ($instances as $instance) {
            if ($instance->containerPrefix === $instanceName) {
                if ($instance->containerPrefix === $default) {
                    $instance->isDefault = true;
                }
                return $instance;
            }
        }
        return null;
    }

    /**
     * @param RegistryInstance[] $instances
     * @return void
     */
    private function writeInstanceRegistry(array $instances) {
        $rows = [];
        foreach ($instances as $instance) {
            $rows[]= $this->serialzeRegistryInstance($instance);
        }
        $content = implode("\n", $rows);
        $path = $this->getRegistryFilePath();
        file_put_contents($path, $content);
        $this->invalidateCachedMainConfig();
    }

    private function upsertRegistryInstance(string $uuid, string $instanceRecipePath, string $containerPrefix) {
        $path = OS::realPath($instanceRecipePath);
        $instances = $this->getInstanceRegistry();
        $globalConfig = $this->getMainConfig();

        if (empty($instances[$uuid])) {
            // Check that the recipe path is not registered under another uuid.
            $possibleDuplicates = count(array_filter($instances, fn($inst) => $inst->recipePath === $path || $inst->containerPrefix === $containerPrefix));
            if ($possibleDuplicates > 0) {
                $this->cli->warning("Instance for $containerPrefix is already registered with different uuid(s).");
                $proceed = $this->cli->promptYesNo('Deduplicate existing registered instances?');
                if (!$proceed) {
                    $this->cli->warning("Cannot proceed unless registry is de-duplicated for $containerPrefix");
                    die;
                }
                $instances = array_filter($instances, fn($inst) => $inst->containerPrefix !== $containerPrefix);
            }
        }

        // Allocate proxy mode port if needed
        $proxyModePort = null;
        if ($globalConfig->useProxy) {
            $proxyModePort = $this->allocateProxyPort($instances);
        }

        $instances[$uuid] = new RegistryInstance($uuid, $instanceRecipePath, $containerPrefix, $proxyModePort);

        $this->writeInstanceRegistry($instances);
    }

   public function registerInstance(string $instanceRecipePath, ?string $uuid, string $containerPrefix): string {
        $uuid = $uuid ?? uniqid();
        $this->upsertRegistryInstance($uuid, $instanceRecipePath, $containerPrefix);
        // We need to now put the uuid into the .mchef folder corresponding to the recipe.
        $mchefPath = dirname($instanceRecipePath).'/.mchef';
        file_put_contents($mchefPath.'/registry_uuid.txt', $uuid);
       $this->invalidateCachedMainConfig();
        return $uuid;
    }

    public function getMainConfig(): GlobalConfig {
        if (TestingHelpers::isPHPUnit()) {
            // Always reset config to null.
            static::$config = null;
        }
        if (static::$config !== null) {
            return static::$config;
        }
        $configPath = $this->mainConfigPath();
        if (!file_exists($configPath)) {
            static::$config = new GlobalConfig();
            return static::$config;
        }
        static::$config = GlobalConfig::fromJSONFile($configPath);
        return static::$config;
    }

    public function writeMainConfig(GlobalConfig $config) {
        $config->toJSONFile($this->mainConfigPath());
        $this->invalidateCachedMainConfig();
    }

    public function setMainConfigField(string $field, $value) {
        $this->invalidateCachedMainConfig();
        $mainConfig = $this->getMainConfig();
        $reflection = new \ReflectionClass(GlobalConfig::class);

        if (!$reflection->hasProperty($field)) {
            throw new \InvalidArgumentException("Invalid config field: $field");
        }

        $property = $reflection->getProperty($field);
        $mainConfig->$field = $this->normalizeConfigValueForProperty($property, $value, $field);
        $this->writeMainConfig($mainConfig);
    }

    private function normalizeConfigValueForProperty(\ReflectionProperty $property, mixed $value, string $field): mixed {
        $type = $property->getType();

        if ($type === null) {
            return $value;
        }

        if ($value === null) {
            if ($type->allowsNull()) {
                return null;
            }
            throw new \InvalidArgumentException("Config field '$field' does not allow null values");
        }

        if ($type instanceof \ReflectionNamedType) {
            return $this->normalizeConfigValueForNamedType($type, $value, $field);
        }

        if ($type instanceof \ReflectionUnionType) {
            foreach ($type->getTypes() as $namedType) {
                if ($namedType instanceof \ReflectionNamedType && $namedType->getName() === 'null') {
                    continue;
                }
                try {
                    if ($namedType instanceof \ReflectionNamedType) {
                        return $this->normalizeConfigValueForNamedType($namedType, $value, $field);
                    }
                } catch (\InvalidArgumentException $e) {
                    continue;
                }
            }
            throw new \InvalidArgumentException("Invalid value type for config field '$field'");
        }

        return $value;
    }

    private function normalizeConfigValueForNamedType(\ReflectionNamedType $type, mixed $value, string $field): mixed {
        $typeName = $type->getName();

        if ($type->isBuiltin()) {
            if ($typeName === 'string') {
                if (!is_string($value)) {
                    throw new \InvalidArgumentException("Config field '$field' expects a string");
                }
                return $value;
            }
            if ($typeName === 'bool') {
                if (!is_bool($value)) {
                    throw new \InvalidArgumentException("Config field '$field' expects a boolean");
                }
                return $value;
            }
            if ($typeName === 'int') {
                if (!is_int($value)) {
                    throw new \InvalidArgumentException("Config field '$field' expects an integer");
                }
                return $value;
            }
            if ($typeName === 'float') {
                if (!is_float($value) && !is_int($value)) {
                    throw new \InvalidArgumentException("Config field '$field' expects a float");
                }
                return (float) $value;
            }
            if ($typeName === 'array') {
                if (!is_array($value)) {
                    throw new \InvalidArgumentException("Config field '$field' expects an array");
                }
                return $value;
            }

            return $value;
        }

        if (!enum_exists($typeName)) {
            if ($value instanceof $typeName) {
                return $value;
            }
            throw new \InvalidArgumentException("Config field '$field' expects an instance of $typeName");
        }

        if ($value instanceof $typeName) {
            return $value;
        }

        $enumOptions = $this->formatEnumOptions($typeName);

        if (!is_string($value) && !is_int($value)) {
            throw new \InvalidArgumentException("Config field '$field' expects a valid enum value for $typeName. Allowed values: $enumOptions");
        }

        if (!is_subclass_of($typeName, \BackedEnum::class)) {
            throw new \InvalidArgumentException("Config field '$field' enum type $typeName is not a backed enum");
        }

        $enumValue = $typeName::tryFrom($value);
        if ($enumValue === null) {
            throw new \InvalidArgumentException("Invalid enum value for config field '$field': $value. Allowed values: $enumOptions");
        }

        return $enumValue;
    }

    private function formatEnumOptions(string $enumType, int $maxOptions = 15): string {
        $cases = $enumType::cases();
        $total = count($cases);
        $visibleCases = array_slice($cases, 0, $maxOptions);

        $labels = array_map(function($case) use ($enumType) {
            if (is_subclass_of($enumType, \BackedEnum::class)) {
                return (string)$case->value;
            }
            return $case->name;
        }, $visibleCases);

        $formatted = implode(', ', $labels);
        if ($total > $maxOptions) {
            $formatted .= ', ...';
        }

        return $formatted;
    }

    /**
     * Allocate the next available proxy port starting from 8100
     * @param RegistryInstance[] $instances
     * @return int
     */
    private function allocateProxyPort(array $instances): int {
        $startPort = 8100;
        $usedPorts = [];

        // Collect all currently used proxy ports
        foreach ($instances as $instance) {
            if ($instance->proxyModePort !== null) {
                $usedPorts[] = $instance->proxyModePort;
            }
        }

        // Find the next available port
        $port = $startPort;
        while (in_array($port, $usedPorts)) {
            $port++;
        }

        return $port;
    }

    /**
     * Remove an instance from the registry by instance name.
     * 
     * @param string $instanceName The container prefix (instance name) to remove
     * @return bool True if instance was found and removed, false otherwise
     */
    public function deregisterInstance(string $instanceName): bool {
        $instances = $this->getInstanceRegistry();
        $originalCount = count($instances);
        
        // Filter out the instance with the matching container prefix
        $filteredInstances = array_filter($instances, function($instance) use ($instanceName) {
            return $instance->containerPrefix !== $instanceName;
        });
        
        if (count($filteredInstances) < $originalCount) {
            $this->writeInstanceRegistry($filteredInstances);
            return true;
        }
        
        return false;
    }
}
