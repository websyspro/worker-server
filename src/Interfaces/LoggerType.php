<?php

namespace Websyspro\WorkerServer\Interfaces;

enum LoggerType
{
  case INFO;
  case ERROR;
  case WARN;
  case DEBUG;
}