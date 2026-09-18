<?php

namespace Websyspro\WorkerServer\Decorators;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Query
{
  public function __construct(
    public readonly string $key = ""
  ){}
}
