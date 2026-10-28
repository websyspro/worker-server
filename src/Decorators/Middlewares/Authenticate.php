<?php

namespace Websyspro\WorkerServer\Decorators\Middlewares;

use Attribute;
use Throwable;
use Websyspro\WorkerServer\Middleware;
use Websyspro\WorkerServer\Request;
use Websyspro\WorkerServer\Response;
use Websyspro\WorkerServer\Container;
use Websyspro\WorkerServer\Interfaces\IAuthenticationHandler;

#[Attribute(Attribute::TARGET_CLASS)]
class Authenticate extends Middleware
{
  public function execute(Request $request, callable $next): Response|null
  {
    // Tenta obter o AuthenticationHandler do Container
    try {
      $authHandler = Container::make(IAuthenticationHandler::class);
      
      if (!$authHandler->validate($request)) {
        return Response::json([
          "message" => "Unauthorized",
          "error" => "Valid Bearer token is required"
        ], 401);
      }
    } catch ( Throwable $e) {
      // Se não conseguir obter o handler, retorna erro
      return Response::json([
        "message" => "Unauthorized",
        "error" => "Authentication handler not configured"
      ], 401);
    }

    // Autenticação bem-sucedida, continua para o próximo middleware
    return $next($request);
  }
}
