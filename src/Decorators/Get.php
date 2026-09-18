<?php

namespace Websyspro\WorkerServer\Decorators;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class Get
{
  public function __construct(
    public readonly string $path = "/"
  ){}
}
