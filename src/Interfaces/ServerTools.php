<?php

namespace Websyspro\WorkerServer\Interfaces;

class ServerTools
{
  public function __construct(
    public int $port,
    public int $apiVersion,
    public int $keepAliveTimeout,
    public int $maxRequests,
    public string $host = PHP_OS_FAMILY === 'Windows' ? 'localhost' : '0.0.0.0'
  ){}
}