<?php

namespace Websyspro\Server\Decorators;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
class File
{
  public function __construct(
    public readonly string $key = ""
  ){}
}
