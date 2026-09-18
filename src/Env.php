<?php

namespace Websyspro\WorkerServer;

use function define;
use function defined;
use function file_exists;
use function file;
use function explode;
use function trim;
use function str_starts_with;
use function str_contains;
use const FILE_IGNORE_NEW_LINES;
use const FILE_SKIP_EMPTY_LINES;

class Env
{
    public static function load(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);

            if (!defined($key)) {
                define($key, $value);
            }
        }
    }
}
