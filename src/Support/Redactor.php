<?php

namespace Rootstuff\OpsMcp\Support;

/**
 * Strips the things a log line should never carry out of the box: passwords
 * and tokens after their usual labels, bearer tokens, cloud and payment
 * keys, credentials inside connection URLs, and long bare secrets. Over
 * redacting a hex id is cheap; leaking a key once is not.
 */
final class Redactor
{
    /** @var list<array{0: string, 1: string}> */
    private const RULES = [
        // Bearer first, so "Authorization: Bearer xyz" keeps the scheme word.
        ['/\bBearer\s+[A-Za-z0-9\-._~+\/]{8,}=*/', 'Bearer [redacted]'],
        // Labels may carry a prefix (DB_PASSWORD, REDIS_PASSWORD, x-api-key).
        ['/([A-Za-z0-9_.-]*(?:password|passwd|pwd|secret|token|api[_-]?key|access[_-]?key|private[_-]?key|authorization|auth)\b)(\s*[=:]\s*|\s*"\s*:\s*"|\s+)(?!Bearer\b)([^\s"\',;]{4,})/i', '$1$2[redacted]'],
        ['/\bAKIA[0-9A-Z]{16}\b/', '[redacted-aws-key]'],
        ['/\b(?:sk|pk|rk|whsec)_(?:live|test)_[A-Za-z0-9]{8,}\b/', '[redacted-stripe-key]'],
        ['/\bgh[pousr]_[A-Za-z0-9]{20,}\b/', '[redacted-github-token]'],
        // Long bare secrets (base64 keys and the like). No '/' in the class,
        // so file paths survive, and it must mix upper, lower and digits, so
        // a 40-character release SHA inside a path is left alone.
        ['/(?<![A-Za-z0-9+])(?=[A-Za-z0-9+]*[A-Z])(?=[A-Za-z0-9+]*[a-z])(?=[A-Za-z0-9+]*[0-9])[A-Za-z0-9+]{40,}={0,2}(?![A-Za-z0-9+])/', '[redacted-long-secret]'],
        ['/(redis:\/\/|rediss:\/\/|mysql:\/\/|pgsql:\/\/|postgres:\/\/|postgresql:\/\/|amqp:\/\/|mongodb:\/\/)([^:\s\/]+):([^@\s]+)@/i', '$1$2:[redacted]@'],
    ];

    public static function redact(string $text): string
    {
        foreach (self::RULES as [$pattern, $replacement]) {
            $text = (string) preg_replace($pattern, $replacement, $text);
        }

        return $text;
    }

    /**
     * Redact every string inside a nested array, keys left alone.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function redactArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = self::redact($value);
            } elseif (is_array($value)) {
                $data[$key] = self::redactArray($value);
            }
        }

        return $data;
    }
}
