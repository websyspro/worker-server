<?php

namespace Websyspro\WorkerServer;

use Websyspro\WorkerServer\Request;
use Websyspro\WorkerServer\Response;

abstract class Middleware
{
  /**
   * Executa o middleware
   * 
   * @param Request $request
   * @param callable $next Próximo middleware ou handler da rota
   * @return Response|null Retorna Response para interromper, null para continuar
   */
  abstract public function execute(Request $request, callable $next): Response|null;
}
