<?php

namespace Websyspro\WorkerServer;

use Websyspro\Logger\Terminal;
use Websyspro\WorkerServer\Enums\LogContext;
use function date;
use function microtime;
use function round;

class Logger
{
  private static float $lastTime = 0.0;

  public static function info(
    string $message,
    LogContext|null $context = null
  ): void {
    static::log( 'INFO', 'green', $message, $context );
  }

  public static function error(
    string $message,
    LogContext|null $context = null
  ): void {
    static::log( 'ERROR', 'red', $message, $context );
  }

  public static function warn(
    string $message,
    LogContext|null $context = null
  ): void {
    static::log( 'WARN', 'yellow', $message, $context );
  }

  public static function debug(
    string $message,
    LogContext|null $context = null
  ): void {
    static::log( 'DEBUG', 'cyan', $message, $context );
  }

  private static function log(
    string $level,
    string $color,
    string $message,
    LogContext|null $context = null
  ): void {
    $now  = microtime( true );
    $diff = static::$lastTime > 0
      ? round(( $now - static::$lastTime ) * 1000 )
      : 0;

    static::$lastTime = $now;
    $timestamp = date( 'Y-m-d H:i:s' );

    if( defined('SERVER_TOOLS') && isset( SERVER_TOOLS['isWorker'] ) && SERVER_TOOLS['isWorker'] === false ){
      $contextLabel = $context !== null ? " [{$context->value}]" : "";
      error_log( "[{$timestamp}]{$contextLabel} {$level} {$message}" );
      return;
    }

    $t = Terminal::init();

    $t->green( "[WorkerServer] [{$timestamp}]" );

    if( $context !== null ){
      $t->spc()->text( "[{$context->value}]" );
    }

    $t->spc()->{$color}( $level );
    $t->spc()->text( "{$message}" )->spc()->{$color}( "+{$diff}ms" )->eof();
  }
}
