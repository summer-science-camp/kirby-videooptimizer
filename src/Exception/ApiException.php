<?php

namespace CircusCirculi\VideoOptimizer\Exception;

use RuntimeException;

class ApiException extends RuntimeException
{
    public function __construct(private readonly int $status, string $message)
    {
        parent::__construct($message);
    }

    public static function fromResponse(int $status, string $body): static
    {
        $decoded = json_decode($body, true);

        // The API answers with {statusCode, statusMessage, message}
        $message = match (true) {
            is_string($decoded['message'] ?? null)          => $decoded['message'],
            is_string($decoded['error']['message'] ?? null) => $decoded['error']['message'],
            is_string($decoded['error'] ?? null)            => $decoded['error'],
            default                                         => null,
        };

        if (is_string($message) === false || $message === '') {
            $message = 'VideoOptimizer API request failed with status ' . $status . '.';
        }

        return new static($status, $message);
    }

    public function status(): int
    {
        return $this->status;
    }
}
