<?php

namespace Websyspro\WorkerServer\Decorators;

use Attribute;
use Websyspro\WorkerServer\Middleware;
use Websyspro\WorkerServer\Request;
use Websyspro\WorkerServer\Response;

#[Attribute(Attribute::TARGET_METHOD)]
class AllowAnonymous
extends Middleware
{
  public function __construct(){}
 
  public function execute(Request $request, callable $next): Response|null
  {

    // Autenticação bem-sucedida, continua para o próximo middleware
    return $next($request);
  }  
}
