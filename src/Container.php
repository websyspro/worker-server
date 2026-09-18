<?php

namespace Websyspro\WorkerServer;

use ReflectionClass;
use ReflectionNamedType;

class Container
{
    /**
     * Resolve uma classe recursivamente, injetando todas as dependencias
     * do constructor automaticamente via type hints.
     */
    public static function make(string $class): object
    {
        $reflection  = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $args = [];
        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();

            if (!($type instanceof ReflectionNamedType) || $type->isBuiltin()) {
                $args[] = $param->isDefaultValueAvailable()
                    ? $param->getDefaultValue()
                    : null;
                continue;
            }

            // Se tem valor default, usa ele em vez de resolver recursivamente
            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
                continue;
            }

            // Resolve recursivamente cada dependencia
            $args[] = static::make($type->getName());
        }

        return $reflection->newInstanceArgs($args);
    }
}
