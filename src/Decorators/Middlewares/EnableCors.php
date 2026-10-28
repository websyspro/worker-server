<?php

namespace Websyspro\WorkerServer\Decorators\Middlewares;

use Attribute;
use Websyspro\WorkerServer\Middleware;
use Websyspro\WorkerServer\Request;
use Websyspro\WorkerServer\Response;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class EnableCors extends Middleware
{
  public function __construct(
    private readonly array $origins = ['*'],
    private readonly array $methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    private readonly array $headers = ['Content-Type', 'Authorization', 'X-Requested-With'],
    private readonly bool $credentials = false,
    private readonly int $maxAge = 86400 // 24 horas
  ){}

  public function execute(Request $request, callable $next): Response|null
  {
    // Se for requisição OPTIONS (preflight), responde imediatamente
    if ($request->method === 'OPTIONS') {
      return $this->buildCorsResponse(new Response(), $request);
    }

    // Processa a requisição normalmente
    $response = $next($request);

    // Adiciona headers CORS na resposta
    return $this->buildCorsResponse($response, $request);
  }

  private function buildCorsResponse(Response $response, Request $request): Response
  {
    $origin = $request->headers['origin'] ?? '';

    // Determina o origin permitido
    $allowedOrigin = $this->getAllowedOrigin($origin);

    if ($allowedOrigin !== null) {
      $response->setHeader('Access-Control-Allow-Origin', $allowedOrigin);

      if ($this->credentials) {
        $response->setHeader('Access-Control-Allow-Credentials', 'true');
      }

      $response->setHeader('Access-Control-Allow-Methods', implode(', ', $this->methods));
      $response->setHeader('Access-Control-Allow-Headers', implode(', ', $this->headers));
      $response->setHeader('Access-Control-Max-Age', (string)$this->maxAge);
      
      // Headers que podem ser expostos ao JavaScript
      $response->setHeader('Access-Control-Expose-Headers', 'Content-Length, Content-Type');
    }

    return $response;
  }

  private function getAllowedOrigin(string $origin): string|null
  {
    // Se permite todos
    if (in_array('*', $this->origins)) {
      return '*';
    }

    // Se o origin está na lista
    if (in_array($origin, $this->origins)) {
      return $origin;
    }

    // Se há wildcard patterns (exemplo: *.example.com)
    foreach ($this->origins as $allowed) {
      if ($this->matchWildcard($allowed, $origin)) {
        return $origin;
      }
    }

    return null;
  }

  private function matchWildcard(string $pattern, string $subject): bool
  {
    $pattern = preg_quote($pattern, '#');
    $pattern = str_replace('\*', '.*', $pattern);
    return (bool)preg_match('#^' . $pattern . '$#', $subject);
  }
}
