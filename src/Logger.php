<?php

namespace Websyspro\WorkerServer;

use function date;
use function microtime;
use function round;
use function sprintf;

class Logger
{
    private static float $lastTime = 0.0;

    public static function info(string $message): void
    {
        static::log('INFO ', "\033[32m", $message);
    }

    public static function error(string $message): void
    {
        static::log('ERROR', "\033[31m", $message);
    }

    public static function warn(string $message): void
    {
        static::log('WARN ', "\033[33m", $message);
    }

    public static function debug(string $message): void
    {
        static::log('DEBUG', "\033[36m", $message);
    }

    private static function log(string $level, string $color, string $message): void
    {
        $now  = microtime(true);
        $diff = static::$lastTime > 0
            ? round(($now - static::$lastTime) * 1000)
            : 0;

        static::$lastTime = $now;

        $timestamp = date('Y-m-d H:i:s');
        $reset     = "\033[0m";

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
