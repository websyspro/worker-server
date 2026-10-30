<?php

namespace Websyspro\WorkerServer;

use Websyspro\WorkerServer\Request;
use Websyspro\WorkerServer\Response;
use Closure;

abstract class Middleware
{
  abstract public function execute(
    Request $request, 
    Closure $next
  ): Response|null;
}
