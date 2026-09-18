<?php

namespace Websyspro\WorkerServer\Decorators;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class Delete
{
  public function __construct(
    public readonly string $path = "/"
  ){}
}
