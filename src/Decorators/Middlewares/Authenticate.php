<?php

namespace Websyspro\WorkerServer\Decorators\Middlewares;

use Websyspro\WorkerServer\Container;
use Websyspro\WorkerServer\JwtManager;
use Websyspro\WorkerServer\Middleware;
use Websyspro\WorkerServer\Request;
use Websyspro\WorkerServer\Response;
use Attribute;
use Closure;

#[Attribute(Attribute::TARGET_CLASS)]
class Authenticate
extends Middleware
{
  public function execute(
    Request $request,
    Closure $next
  ): Response|null {
    $authorization = $request->headers['authorization'] ?? null;

    $jwtManager = Container::make(
      JwtManager::class
    );

    $token   = $jwtManager->extractFromBearer( $authorization );
    $payload = $token !== null
      ? $jwtManager->verify( $token )
      : null;

    if( $payload === null ){
      return Response::json([
        "message" => "Unauthorized access."
      ], 401);
    }

    $request->user = $payload;
    return $next( $request );
  }
}
