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

    /** @var JwtManager $jwtManager */
    $jwtManager = Container::make( JwtManager::class );

    $token   = $jwtManager->extractFromBearer( $authorization );
    $payload = $token !== null
      ? $jwtManager->verify( $token )
      : null;

    if( $payload === null ){
      return Response::json([
        "message" => "Unauthorized",
        "error" => "Valid Bearer token is required"
      ], 401);
    }

    // Injeta o payload do JWT no request para uso nos controllers
    $request->user = $payload;

    return $next( $request );
  }
}
