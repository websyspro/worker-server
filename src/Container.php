<?php

namespace Websyspro\WorkerServer;

use ReflectionClass;
use ReflectionNamedType;

class Container
{
  private static array $bindings  = [];
  private static array $singletons = [];

  // Registra uma instância singleton — sempre retorna a mesma
  public static function singleton(
    string $abstract,
    object $instance
  ): void {
    static::$singletons[ $abstract ] = $instance;
  }

  // Registra um binding de interface → implementação concreta
  public static function bind(
    string $abstract,
    string $concrete
  ): void {
    static::$bindings[ $abstract ] = $concrete;
  }

  // Resolve uma classe, respeitando singletons e bindings registrados
  public static function make(
    string $class
  ): object {
    // Retorna singleton se existir
    if( isset( static::$singletons[ $class ] ) ){
      return static::$singletons[ $class ];
    }

    // Resolve binding de interface → classe concreta
    if( isset( static::$bindings[ $class ] ) ){
      $class = static::$bindings[ $class ];
    }

    $reflection  = new ReflectionClass( $class );
    $constructor = $reflection->getConstructor();

    if( $constructor === null ){
      return $reflection->newInstance();
    }

    $args = [];
    foreach( $constructor->getParameters() as $param ){
      $type = $param->getType();

      if( !( $type instanceof ReflectionNamedType ) || $type->isBuiltin() ){
        $args[] = $param->isDefaultValueAvailable()
          ? $param->getDefaultValue()
          : null;
        continue;
      }

      // Se tem valor default, usa ele em vez de resolver recursivamente
      if( $param->isDefaultValueAvailable() ){
        $args[] = $param->getDefaultValue();
        continue;
      }

      // Resolve recursivamente cada dependência
      $args[] = static::make( $type->getName() );
    }

    return $reflection->newInstanceArgs( $args );
  }
}
