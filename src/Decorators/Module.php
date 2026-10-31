<?php

namespace Websyspro\WorkerServer\Decorators;

use Attribute;
use ReflectionAttribute;
use ReflectionClass;

#[Attribute(Attribute::TARGET_CLASS)]
class Module
{
  public function __construct(
    public readonly string $name = "",
    public readonly array $controllers = [],
    public readonly array $entities = []
  ){}

  public static function getInstance(
    ReflectionClass $refClass   
  ): Module|null {
    $refClassAttrs = $refClass->getAttributes(
      Module::class
    );

    if( empty( $refClassAttrs )){
      return null;
    }

    [ $instanceModule ] = array_map(
      fn( ReflectionAttribute $refAttr ) => (
        $refAttr->newInstance()
      ), $refClassAttrs
    );
    
    return $instanceModule instanceof Module 
      ? $instanceModule 
      : null;
  }

  public static function getPrefix(
    ReflectionClass $refClass
  ): string {
    $prefix = preg_replace(
      "#Module$#", "", $refClass->getShortName()
    );

    return mb_strtolower( $prefix );
  }
}
