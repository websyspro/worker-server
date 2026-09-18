<?php

namespace Websyspro\Server\Decorators;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class Delete
{
  public function __construct(
    public readonly string $path = "/"
  ){}
}
