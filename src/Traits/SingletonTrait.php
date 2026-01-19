<?php

namespace App\Traits;

use App\Interfaces\SingletonInterface;
use App\MChefCLI;
use App\Service\AbstractService;
use App\StaticVars;
use PHPUnit\Framework\MockObject\MockObject;

trait SingletonTrait {

    protected MChefCLI|MockObject $cli;

    protected function __construct() {
        $this->cli = StaticVars::$cli;
    }

    protected function init() {
        // Do nothing by default.
        // Override this in your class if you want something to happen automatically when the class is instantiated.
    }

    protected static function getServiceClassesFromClassProperties(): array {
        $reflection = new \ReflectionClass(static::class);
        $properties = $reflection->getProperties();
        $classes = [];
        foreach ($properties as $property) {
            $type = $property->getType();
            $propName = $property->getName();
            // Only proceed if it's a named type (class/interface)
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $className = $type->getName();
                // Check that class inherits from AbstractService and add to list.
                if (is_subclass_of($className, AbstractService::class)) {
                    $classes[$propName] = $className;
                }
            }
        }
        return $classes;
    }

    protected static function setup_singleton(bool $reset = false): SingletonInterface {
        static $instances = [];
        $class = get_called_class();
        if (!$reset && !empty($instances[$class])) {
            return $instances[$class];
        }
        $instance = new $class();
        //echo ("\n Setting up dependency injection for ".get_class($instance));
        $instances[$class] = $instance;
        // Note - this has to happen after the instance has been registered, or you will
        // get circular dependency issues.
        static::dependecyInjection($instance);
        
        $instance->init();

        return $instance;
    }

    protected static function dependecyInjection(SingletonInterface $instance): void {
        // New dependency injection via properties.
        $serviceClasses = self::getServiceClassesFromClassProperties();
        $serviceClassInstances = [];
        foreach ($serviceClasses as $propName => $serviceClass) {
            $serviceClassInstances[$propName] = $serviceClass::instance();
        }
        foreach ($serviceClasses as $propName => $serviceClass) {
            $property = (new \ReflectionClass($instance))->getProperty($propName);
            //echo "\n Applying service class $serviceClass for ".get_class($instance);
            $property->setValue($instance, $serviceClassInstances[$propName]);
        }
    }
}
