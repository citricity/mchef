<?php

namespace App\Service;

use App\Attributes\ArrayOf;
use App\Model\AbstractModel;
use App\Traits\SingletonTrait;
use ReflectionClass;
use ReflectionProperty;
use splitbrain\phpcli\Exception;

final class ModelJSONDeserializer extends AbstractService {

    use SingletonTrait;

    public static function instance(): ModelJSONDeserializer {
        return self::setup_singleton();
    }

    /**
     * Deserialize JSON string to a model instance
     */
    public function deserialize(string $json, string $modelClass): AbstractModel {
        $data = json_decode($json, true);
        if ($data === null) {
            throw new Exception("Invalid JSON: $json");
        }

        return $this->deserializeData($data, $modelClass);
    }

    /**
     * Deserialize data array/object to a model instance
     */
    public function deserializeData(array|object $data, string $modelClass): AbstractModel {
        $data = is_array($data) ? $data : (array) $data;

        if (!class_exists($modelClass)) {
            throw new Exception("Model class does not exist: $modelClass");
        }

        $reflection = new ReflectionClass($modelClass);
        $constructor = $reflection->getConstructor();

        if (!$constructor) {
            throw new Exception("Model class has no constructor: $modelClass");
        }

        $parameters = $constructor->getParameters();
        $constructorArgs = [];

        foreach ($parameters as $parameter) {
            $paramName = $parameter->getName();
            $paramType = $parameter->getType();

            // Get the value from data or use default
            if (array_key_exists($paramName, $data)) {
                $value = $data[$paramName];

                // Process the value based on type and attributes
                $processedValue = $this->processValue($value, $parameter, $reflection);
                $constructorArgs[$paramName] = $processedValue;
            } else if ($parameter->isDefaultValueAvailable()) {
                $constructorArgs[$paramName] = $parameter->getDefaultValue();
            } else if ($parameter->allowsNull()) {
                $constructorArgs[$paramName] = null;
            } else {
                throw new Exception("Required parameter '$paramName' missing for $modelClass");
            }
        }

        return new $modelClass(...$constructorArgs);
    }

    /**
     * Process a value based on parameter type and attributes
     */
    private function processValue(mixed $value, \ReflectionParameter $parameter, ReflectionClass $classReflection): mixed {
        if ($value === null) {
            return null;
        }

        // Check for ArrayOf attribute on the property
        $property = $this->getPropertyForParameter($parameter, $classReflection);
        if ($property) {
            $arrayOfAttribute = $this->getArrayOfAttribute($property);
            if ($arrayOfAttribute && is_array($value)) {
                return $this->processArrayWithAttribute($value, $arrayOfAttribute);
            }
        }

        // Handle based on parameter type
        $paramType = $parameter->getType();
        if ($paramType) {
            return $this->processValueByType($value, $paramType);
        }

        return $value;
    }

    /**
     * Get the property that corresponds to a constructor parameter
     */
    private function getPropertyForParameter(\ReflectionParameter $parameter, ReflectionClass $classReflection): ?ReflectionProperty {
        $paramName = $parameter->getName();

        try {
            return $classReflection->getProperty($paramName);
        } catch (\ReflectionException $e) {
            return null;
        }
    }

    /**
     * Get ArrayOf attribute from a property
     */
    private function getArrayOfAttribute(ReflectionProperty $property): ?ArrayOf {
        $attributes = $property->getAttributes(ArrayOf::class);
        return $attributes ? $attributes[0]->newInstance() : null;
    }

    /**
     * Process array value with ArrayOf attribute
     */
    private function processArrayWithAttribute(array $value, ArrayOf $attribute): array {
        $result = [];

        // Get the types from the attribute
        $types = $attribute->types;

        foreach ($value as $item) {
            $processed = false;

            // Try each type in the attribute
            foreach ($types as $type) {
                if ($type instanceof \App\Enums\TYPE) {
                    // Handle TYPE enum values
                    $expectedType = $type->value;
                    if (gettype($item) === $expectedType) {
                        $result[] = $item;
                        $processed = true;
                        break;
                    }
                } elseif (is_string($type)) {
                    // Handle scalar types
                    if (in_array($type, ['string', 'int', 'integer', 'bool', 'boolean', 'float', 'double', 'array'])) {
                        $actualType = gettype($item);
                        if ($actualType === $type ||
                            ($type === 'int' && $actualType === 'integer') ||
                            ($type === 'bool' && $actualType === 'boolean') ||
                            ($type === 'float' && $actualType === 'double')) {
                            $result[] = $item;
                            $processed = true;
                            break;
                        }
                    }
                    // Handle class types
                    elseif (class_exists($type)) {
                        if (is_array($item) || is_object($item)) {
                            if (is_subclass_of($type, AbstractModel::class)) {
                                $result[] = $this->deserializeData($item, $type);
                            } else {
                                $result[] = new $type(...(array)$item);
                            }
                            $processed = true;
                            break;
                        } elseif (is_object($item) && get_class($item) === $type) {
                            $result[] = $item;
                            $processed = true;
                            break;
                        }
                    }
                }
            }

            // If no type matched, just add the item as-is (for backward compatibility)
            if (!$processed) {
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * Process value based on reflection type
     */
    private function processValueByType(mixed $value, \ReflectionType $type): mixed {
        // Handle union types (e.g., RestoreStructure|string|null)
        if ($type instanceof \ReflectionUnionType) {
            $types = $type->getTypes();
            
            // Try each type in the union to find a match
            foreach ($types as $unionType) {
                if ($unionType instanceof \ReflectionNamedType) {
                    $typeName = $unionType->getName();
                    
                    // Skip null type - we'll handle it separately
                    if ($typeName === 'null' && $value === null) {
                        return null;
                    }
                    
                    // If value is a string and type allows string, pass it through
                    if ($typeName === 'string' && is_string($value)) {
                        return $value;
                    }
                    
                    // If value is an array/object and type is a model class, deserialize it
                    if (class_exists($typeName) && is_subclass_of($typeName, AbstractModel::class)) {
                        if (is_array($value) || is_object($value)) {
                            return $this->deserializeData($value, $typeName);
                        }
                    }
                }
            }
            
            // If no type matched, return value as-is (for backward compatibility)
            return $value;
        }
        
        if ($type instanceof \ReflectionNamedType) {
            $typeName = $type->getName();

            // Handle model classes
            if (class_exists($typeName) && is_subclass_of($typeName, AbstractModel::class)) {
                if (is_array($value) || is_object($value)) {
                    return $this->deserializeData($value, $typeName);
                }
            }

            // Handle scalar types
            return match($typeName) {
                'string' => (string) $value,
                'int' => (int) $value,
                'float' => (float) $value,
                'bool' => (bool) $value,
                'array' => is_array($value) ? $value : [$value],
                default => $value
            };
        }

        return $value;
    }
}
