<?php

namespace Websyspro\WorkerServer\Decorators;

use ReflectionMethod;
use Websyspro\WorkerServer\Decorators\Middlewares\AllowAnonymous;
use Websyspro\WorkerServer\Decorators\Middlewares\Authenticate;
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

  public static function getPrefix(
    Controller $controller
  ): string {
    return sprintf( "%s", trim(
      $controller->prefix, "/" 
    ));
  }

  public static function getJoinPahs(
    array $paths
  ): string {
    return implode( "/", $paths );
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

  public static function getMiddlewaresIsAuthenticate(
    ReflectionClass $refClass
  ): bool {
    $refClassAttrs = $refClass->getAttributes(
      Authenticate::class
    );

    if( empty( $refClassAttrs )){
      return false;
    }

    [ $authenticate ] = $refClassAttrs;
    return $authenticate->getName() === Authenticate::class;
  }

  public static function getMethodsPublics(
    ReflectionClass $refClass
  ): array {
    return $refClass->getMethods(
      ReflectionMethod::IS_PUBLIC
    );
  }  
  
  public static function getMiddlewaresIsAllowAnonymous(
    ReflectionMethod $refMethod
  ): bool {
    $refMethodAttrs = $refMethod->getAttributes(
      AllowAnonymous::class
    );

    if( empty( $refMethodAttrs )){
      return false;
    }

    [ $allowAnonymous ] = $refMethodAttrs;
    return $allowAnonymous->getName() === AllowAnonymous::class;
  } 
}
