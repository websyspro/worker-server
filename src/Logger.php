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

    // No modo Apache (isWorker=false), logs vão para error_log em vez de stdout
    if( defined('SERVER_TOOLS') && isset( SERVER_TOOLS['isWorker'] ) && SERVER_TOOLS['isWorker'] === false ){
      $contextLabel = $context !== null ? " [{$context->value}]" : "";
      error_log( "[{$timestamp}]{$contextLabel} {$level} {$message}" );
      return;
    }

    $t = Terminal::init();

    // [timestamp]
    $t->text( "[{$timestamp}]" );

    // [Context] em amarelo (opcional)
    if( $context !== null ){
      $t->text( " " )->yellow( "[{$context->value}]" );
    }

    // LEVEL na cor do nível
    $t->text( " " )->{$color}( $level );

    // mensagem e +Xms
    $t->text( "  {$message} " )->{$color}( "+{$diff}ms" )->eof();
  }
}
