<?php

namespace App\Helpers;

class Testing {
    public static function setRestrictedProperty($object, $prop, $val) {
        $reflection = new \ReflectionObject($object);
        while ($reflection) {
            if ($reflection->hasProperty($prop)) {
                $property = $reflection->getProperty($prop);
                $property->setValue($object, $val);
                return;
            }
            $reflection = $reflection->getParentClass();
        }

        throw new \Exception("Property '$prop' does not exist on object");
    }
}
