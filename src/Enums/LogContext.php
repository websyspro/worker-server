<?php

namespace Websyspro\WorkerServer\Enums;

enum LogContext: string
{
  case Controller  = 'Controller';
  case Service     = 'Service';
  case Repository  = 'Repository';
  case Middleware  = 'Middleware';
  case Module      = 'Module';
  case Route       = 'Route';
  case Database    = 'Database';
  case Worker      = 'Worker';
  case WebSocket   = 'WebSocket';
  case Queue       = 'Queue';
  case Cache       = 'Cache';
  case Auth        = 'Auth';
  case Entity      = 'Entity';
}
