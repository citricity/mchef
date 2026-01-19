<?php

namespace App\Traits;

use App\Exceptions\ExecFailed;

trait CallRestrictedMethodTrait {
    protected function callRestricted(object $object, string $methodName, $args) {
        $reflector = new \ReflectionClass( get_class($object) );
        $method = $reflector->getMethod( $methodName );

        return $method->invokeArgs( $object, $args );

    }
}
