<?php

namespace SummerScienceCamp\VideoOptimizer\Exception;

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
        $message = $decoded['error']['message'] ?? $decoded['error'] ?? $decoded['message'] ?? null;

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
