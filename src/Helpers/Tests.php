<?php

namespace App\Helpers;

class Tests {
    public static function setRestrictedProperty($object, $prop, $val) {
        // Warn if accessed outside of a phpunit test (optional)
        // Use reflection to set protected/private property
        $reflection = new \ReflectionObject($object);
        if ($reflection->hasProperty($prop)) {
            $property = $reflection->getProperty($prop);
            $property->setValue($object, $val);
        } else {
            throw new \Exception("Property '$prop' does not exist on object");
        }
    }
}
