<?php

namespace Websyspro\WorkerServer;

use ReflectionClass;
use ReflectionNamedType;

abstract class Model
{
    /**
     * Hidrata o model a partir de um array ou objeto.
     * Apenas propriedades declaradas na classe sao preenchidas.
     */
    public static function from(mixed $data): static
    {
        $instance   = new static();
        $reflection = new ReflectionClass($instance);
        $data       = is_object($data) ? (array) $data : (array) $data;

        foreach ($reflection->getProperties() as $property) {
            $name = $property->getName();
            if (!array_key_exists($name, $data)) {
                continue;
            }

            $type  = $property->getType();
            $value = $data[$name];

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $value = $type->getName()::from($value);
            }

            $property->setValue($instance, $value);
        }

        return $instance;
    }
}
