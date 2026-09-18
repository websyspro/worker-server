<?php

namespace Websyspro\WorkerServer\Enums;

enum RequestMethod
{
  case GET;
  case POST;
  case PUT;
  case PATCH;
  case DELETE;
}