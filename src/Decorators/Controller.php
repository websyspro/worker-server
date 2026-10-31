<?php

namespace Websyspro\WorkerServer\Decorators;

use Websyspro\WorkerServer\Middleware;
use ReflectionAttribute;
use ReflectionClass;
use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Controller
{
  public function __construct(
    public readonly string $prefix = ""
  ){}

  public static function getInstance(
    ReflectionClass $refClass   
  ): Controller|null {
    $refClassAttrs = $refClass->getAttributes(
      Controller::class
    );

    if( empty( $refClassAttrs )){
      return null;
    }

    [ $instanceModule ] = array_map(
      fn( ReflectionAttribute $refAttr ) => (
        $refAttr->newInstance()
      ), $refClassAttrs
    );
    
    return $instanceModule instanceof Controller 
      ? $instanceModule 
      : null;
  } 
  
  public static function getMiddlewares(
    ReflectionClass $refClass,
    array $middlewares = []
  ): array {
    $middlewares = array_filter(
      $refClass->getAttributes(), fn( ReflectionAttribute $refAttr ) => (
        is_subclass_of( $refAttr->getName(), Middleware::class )
      )
    );

    return array_map(
      fn( ReflectionAttribute $refAttr ) => $refAttr->getName(),
        array_values( $middlewares )
    );
  }
}
