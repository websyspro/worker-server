<?php

namespace Websyspro\WorkerServer\Decorators;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Module
{
  public function __construct(
    public readonly string $name = "",
    public readonly array $controllers = [],
    public readonly array $entities = []
  ){}
}
