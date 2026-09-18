<?php

namespace Websyspro\Server\Decorators;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Controller
{
  public function __construct(
    public readonly string $prefix = ""
  ){}
}
