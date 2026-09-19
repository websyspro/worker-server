<?php

namespace Websyspro\WorkerServer;

use ReflectionClass;
use ReflectionNamedType;
use function is_object;
use function array_key_exists;

abstract class Model
{
  public static function from(
    mixed $data
  ): static {
    $instance = new static();
    $reflection = new ReflectionClass($instance);
    $data = is_object( $data ) ? (array)$data : (array)$data;

    foreach( $reflection->getProperties() as $property ){
      $name = $property->getName();
      if( !array_key_exists( $name, $data )){
        continue;
      }

      $type = $property->getType();
      $value = $data[ $name ];

      if( $type instanceof ReflectionNamedType && !$type->isBuiltin()){
        $value = $type->getName()::from( $value );
      }

      $property->setValue(
        $instance, $value
      );
    }

    return $instance;
  }
}
