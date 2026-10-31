<?php

namespace Websyspro\WorkerServer\Decorators;

use ReflectionMethod;
use Websyspro\WorkerServer\Decorators\Middlewares\AllowAnonymous;
use Websyspro\WorkerServer\Decorators\Middlewares\Authenticate;
use Websyspro\WorkerServer\Middleware;
use function sprintf;
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

  public static function getHandler(
    ReflectionClass $refClass, 
    ReflectionMethod $method
  ): array {
    return [ $refClass->getName(), $method->getName() ];
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

  public static function getMiddlewaresFromController(
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

  public static function getMiddlewaresFromMethod(
    ReflectionMethod $refMethod,
    array $middlewares = []
  ): array {
    $middlewares = array_filter(
      $refMethod->getAttributes(), fn( ReflectionAttribute $refAttr ) => (
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

  public static function getMethodInstance(
    ReflectionMethod $methodPublic,
    string $requestTypes  
  ): Get|Post|Put|Patch|Delete|null {
    $refMethodPublic = $methodPublic->getAttributes(
      $requestTypes 
    );

    if( empty( $refMethodPublic )){
      return null;
    }

    [ $methodInstance ] = array_map(
      fn( ReflectionAttribute $refAttr ) => (
        $refAttr->newInstance()
      ), $refMethodPublic
    );
    
    return $methodInstance;
  }  

  public static function getMethodsPublics(
    ReflectionClass $refClass
  ): array {
    return $refClass->getMethods(
      ReflectionMethod::IS_PUBLIC
    );
  }  

  public static function getMethodPath(
    Get|Post|Put|Patch|Delete $methdoPublicInstance
  ): string {
    return trim( $methdoPublicInstance->path, "/" );
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

  public static function getRequestTypes(
  ): array {
    return [ Get::class, Post::class, Put::class, Patch::class, Delete::class ];
  }

  public static function getRequestTypeName(
    string $requestType
  ): string {
    $refRequestType = new ReflectionClass( $requestType );
    return strtoupper( $refRequestType->getShortName());
  }  
}