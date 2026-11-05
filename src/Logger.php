<?php

namespace Websyspro\WorkerServer;

use Websyspro\WorkerServer\Enums\LogContext;
use function date;
use function microtime;
use function round;
use function sprintf;

class Logger
{
  private static float $lastTime = 0.0;

  public static function info(
    string $message,
    LogContext|null $context = null
  ): void {
    static::log('INFO ', "\033[32m", $message, $context);
  }

  public static function error(
    string $message,
    LogContext|null $context = null
  ): void {
    static::log('ERROR', "\033[31m", $message, $context);
  }

  public static function warn(
    string $message,
    LogContext|null $context = null
  ): void {
    static::log('WARN ', "\033[33m", $message, $context);
  }

  public static function debug(
    string $message,
    LogContext|null $context = null
  ): void {
    static::log('DEBUG', "\033[36m", $message, $context);
  }

  // Formata o level injetando o contexto no meio da palavra
  // Ex: INFO  + Controller → I[Controller]NFO
  //     ERROR + Service    → E[Service]RROR
  //     WARN  + Module     → W[Module]ARN
  //     DEBUG + Database   → D[Database]EBUG
  private static function formatLevel(
    string $level,
    LogContext|null $context
  ): string {
    if( $context === null ){
      return $level;
    }

    $label = $context->value;
    return substr( $level, 0, 1 )
      . "[{$label}]"
      . ltrim( substr( $level, 1 ) );
  }

  private static function log(
    string $level,
    string $color,
    string $message,
    LogContext|null $context = null
  ): void {
    // No modo Apache (isWorker=false), logs vão para error_log em vez de stdout
    if( defined('SERVER_TOOLS') && isset( SERVER_TOOLS['isWorker'] ) && SERVER_TOOLS['isWorker'] === false ){
      $formattedLevel = static::formatLevel( $level, $context );
      error_log( "[{$formattedLevel}] $message" );
      return;
    }

    $now  = microtime(true);
    $diff = static::$lastTime > 0
      ? round(($now - static::$lastTime) * 1000)
      : 0;

    static::$lastTime = $now;

    $timestamp      = date('Y-m-d H:i:s');
    $formattedLevel = static::formatLevel( $level, $context );
    $reset          = "\033[0m";

    echo sprintf(
      "%s[%s] %s%s %s %s+%sms%s\n",
      $color,
      $timestamp,
      $formattedLevel,
      $reset,
      $message,
      $color,
      $diff,
      $reset
    );
  }
}
