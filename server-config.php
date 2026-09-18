<?php

use Websyspro\WorkerServer\Interfaces\ServerTools;

/**
 * Server Server Runtime
 */
defined( "BASE_DIR" ) || define(
  "BASE_DIR", realpath(
    dirname( __DIR__ ) 
  ) . DIRECTORY_SEPARATOR
);

/**
 * AutoLoad
 */
if( file_exists(  BASE_DIR . "vendor/autoload.php" )){
  require_once BASE_DIR . "vendor/autoload.php";
}

/**
 * Config
 */
if( file_exists(  BASE_DIR . "server-config.php" )){
  return new ServerTools(
    port: 8080, 
    apiVersion: 1, 
    maxRequests: 1000,
    keepAliveTimeout: 30, 
  );
}

/**
 * Fallback
 */
return null;