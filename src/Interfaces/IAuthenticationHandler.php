<?php

namespace Websyspro\WorkerServer\Interfaces;

use Websyspro\WorkerServer\Request;

interface IAuthenticationHandler
{
  /**
   * Valida o token de autenticacao
   * 
   * @param Request $request
   * @return bool True se autenticado, False caso contrario
   */
  public function validate(Request $request): bool;

  /**
   * Extrai o token do header Authorization
   * 
   * @param Request $request
   * @param string $scheme (Bearer, Basic, etc.)
   * @return string|null
   */
  public function extractToken(Request $request, string $scheme = "Bearer"): string|null;
}
