<?php

declare(strict_types=1);

namespace Breakfast\Platform\Support;

/**
 * Structured JSON-lines application logger.
 *
 * Writes one JSON object per line to a dated file under storage/logs. Values
 * are passed through a redactor so secrets, tokens and authorization headers
 * never reach disk. Absolute server paths are stripped from messages.
 */
final class Logger
{
    /** @var list<string> keys whose values are always masked */
    private const SENSITIVE = [
        'password', 'secret', 'token', 'authorization', 'signature',
        'api_key', 'apikey', 'key', 'credential', 'cookie', 'session',
    ];

    public function __construct(private readonly string $logDir)
    {
    }

    /** @param array<string,mixed> $context */
    public function info(string $channel, string $message, array $context = []): void
    {
        $this->write('info', $channel, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function warning(string $channel, string $message, array $context = []): void
    {
        $this->write('warning', $channel, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function error(string $channel, string $message, array $context = []): void
    {
        $this->write('error', $channel, $message, $context);
    }

    /** @param array<string,mixed> $context */
    private function write(string $level, string $channel, string $message, array $context): void
    {
        if (is_dir($this->logDir) === false) {
            @mkdir($this->logDir, 0770, true);
        }

        $record = [
            'ts'      => Clock::nowIso(),
            'level'   => $level,
            'channel' => $channel,
            'message' => $message,
            'context' => $this->redact($context),
        ];

        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($line === false) {
            return;
        }

        $file = $this->logDir . '/app-' . Clock::now()->format('Y-m-d') . '.log';
        @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function redact(array $context): array
    {
        $clean = [];

        foreach ($context as $key => $value) {
            $lower = strtolower((string) $key);
            $masked = false;

            foreach (self::SENSITIVE as $needle) {
                if (str_contains($lower, $needle)) {
                    $clean[$key] = '***';
                    $masked = true;
                    break;
                }
            }

            if ($masked) {
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = $this->redact($value);
            } elseif (is_scalar($value) || $value === null) {
                $clean[$key] = $value;
            } else {
                $clean[$key] = '[object]';
            }
        }

        return $clean;
    }
}
