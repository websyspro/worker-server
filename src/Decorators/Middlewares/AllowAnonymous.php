<?php

namespace Websyspro\WorkerServer\Decorators\Middlewares;

use Websyspro\WorkerServer\Middleware;
use Websyspro\WorkerServer\Request;
use Websyspro\WorkerServer\Response;
use Attribute;
use Closure;

#[Attribute(Attribute::TARGET_METHOD)]
class AllowAnonymous
extends Middleware
{
  public function __construct(){}
 
  public function execute(
    Request $request, 
    Closure $next
  ): Response|null {

    return $next($request);
  }  
}
