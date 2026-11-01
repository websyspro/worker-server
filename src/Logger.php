<?php

namespace Websyspro\WorkerServer;

use DateTime;
use Websyspro\Logger\Terminal;
use Websyspro\WorkerServer\Interfaces\LoggerType;
use function date;
use function microtime;
use function round;
use function sprintf;
use function defined;

class Logger
{
  private static float $time = 0.0;

  public static function info(
    string $message
  ): void {
    Logger::log( LoggerType::INFO, $message );
  }

  public static function error(
    string $message
  ): void {
    Logger::log( LoggerType::ERROR, $message );
  }

  public static function warn(
    string $message
  ): void {
    Logger::log( LoggerType::WARN, $message );
  }

  public static function debug(
    string $message
  ): void {
    Logger::log( LoggerType::DEBUG, $message );
  }

  private static function isWorker(
  ): bool {
    if( defined( "SERVER_TOOLS" ) === false ){
      return false;
    }

    if( isset( SERVER_TOOLS[ "isWorker" ]) === false ){
      return false;
    }

    return SERVER_TOOLS[ "isWorker" ];
  }

  private static function logDiff(
  ): string {
    $now = microtime(true);
    $diff = Logger::$time > 0
      ? round(( $now - Logger::$time ) * 1000 ) : 0;

    Logger::$time = $now;
    return sprintf( "+%sms", $diff );
  }

  private static function logBase(
  ): string {
    return sprintf( "[WorkerServer] %s -", getmygid() );
  }

  private static function logTime(
  ): string {
    return (new DateTime())->format( "Y-m-d H:i:s.v" );
  }

  private static function log(
    LoggerType $loggerType,
    string $message
  ): void {
    if( Logger::isWorker() ){
      error_log( $message );
    } else {
      Terminal::init()
        ->green( Logger::logBase() )->spc()
        ->white( Logger::logTime() )->spc()
        ->green( $message )->spc()   
        ->yellow( Logger::logDiff() )
        ->eof();
    }
  }

  private static function log_(
    string $level, string $color, string $message
  ): void {
    // No modo Apache (isWorker=false), logs vão para error_log em vez de stdout
    if( defined('SERVER_TOOLS') && isset( SERVER_TOOLS['isWorker'] ) && SERVER_TOOLS['isWorker'] === false ){
      error_log( "[$level] $message" );
      return;
    }

    $now = microtime(true);
    $diff = static::$time > 0
        ? round(($now - static::$time) * 1000)
        : 0;

    static::$time = $now;

    $timestamp = date('Y-m-d H:i:s');
    $reset = "\033[0m";

    echo sprintf(
      "%s[%s] %s%s %s %s+%sms%s\n",
      $color,
      $timestamp,
      $level,
      $reset,
      $message,
      $color,
      $diff,
      $reset
    );
  }
}
