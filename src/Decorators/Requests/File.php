<?php

namespace Websyspro\WorkerServer\Decorators\Requests;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
class File
{
  public function __construct(
    public readonly string $key = ""
  ){}
}
