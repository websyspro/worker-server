<?php

namespace Websyspro\WorkerServer\Decorators\Requests;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Param
{
  public function __construct(
    public readonly string $key = ""
  ){}
}
