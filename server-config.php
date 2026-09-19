<?php

use Websyspro\Connection\Enums\DriverType;

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

if( defined( "CONNECT_DETAILS_MYSQL" ) === false ){
  define( "CONNECT_DETAILS_MYSQL", (object)[
    "driver" => DriverType::MySql, 
    "host" => "localhost", 
    "port" => "3308", 
    "name" => "app",
    "user" => "root", 
    "pass" => "Qazwsx@123"
  ]);
}

if( defined( "CONNECT_DETAILS_SQLSERVER" ) === false ){
  define( "CONNECT_DETAILS_SQLSERVER", (object)[
    "driver" => DriverType::SqlServer,
    "host" => "localhost",
    "port" => "1433",
    "name" => "app",
    "user" => "sa",
    "pass" => "Qazwsx@123"
  ]);
}

if( defined( "CONNECT_DETAILS_POSTGRESSQL" ) === false ){
  define( "CONNECT_DETAILS_POSTGRESSQL", (object)[
    "driver" => DriverType::PostgreSQL,
    "host" => "localhost",
    "port" => "5434",
    "name" => "app",
    "user" => "root",
    "pass" => "Qazwsx@123"
  ]);
}

/**
 * Connect
 */
if( defined( "CONNECT_DETAILS" ) === false ){
  define( "CONNECT_DETAILS", CONNECT_DETAILS_MYSQL );
}

/**
 * Config
 */
if( defined( "SERVER_TOOLS" ) === false ){
  define( "SERVER_TOOLS", [
    "port" => 3000,
    "apiVersion" => 1,
    "maxRequests" => 1000,
    "keepAliveTimeout" => 30
  ]);
}