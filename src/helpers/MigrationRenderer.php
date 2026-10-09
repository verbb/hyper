<?php
namespace verbb\hyper\helpers;

use verbb\hyper\migrations\plugins\MigrationLine;
use verbb\hyper\migrations\plugins\MigrationResult;

use craft\helpers\Console;

class MigrationRenderer
{
    // Static Methods
    // =========================================================================

    public static function sanitizeConsoleText(string $text): string
    {
        $sanitized = preg_replace('/[\x{0000}-\x{001F}\x{007F}-\x{009F}]/u', '', $text);

        if ($sanitized !== null) {
            return $sanitized;
        }

        return preg_replace('/[\x00-\x1F\x7F-\x9F]/', '', $text) ?? '';
    }

    public static function renderToConsole(array $lines): void
    {
        foreach ($lines as $line) {
            if (!$line instanceof MigrationLine) {
                continue;
            }

            $color = match ($line->level) {
                'success' => Console::FG_GREEN,
                'warning' => Console::FG_YELLOW,
                'error' => Console::FG_RED,
                default => Console::FG_GREY,
            };

            $prefix = $line->depth > 0 ? '> ' : '';

            Console::stdout($prefix . self::sanitizeConsoleText($line->message) . PHP_EOL, $color);
        }
    }

    public static function renderResultToConsole(MigrationResult $result): void
    {
        self::renderToConsole($result->lines);

        if ($result->stats !== []) {
            Console::stdout(PHP_EOL . 'Summary:' . PHP_EOL, Console::FG_YELLOW);

            foreach ($result->stats as $key => $value) {
                $key = self::sanitizeConsoleText((string)$key);
                $value = self::sanitizeConsoleText((string)$value);
                Console::stdout("- {$key}: {$value}" . PHP_EOL, Console::FG_GREY);
            }
        }
    }
}
