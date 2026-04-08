<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitOllama\Exception;

final class OllamaException extends \RuntimeException
{
    public static function connectionFailed(string $baseUrl, string $reason = ''): self
    {
        $message = "Cannot connect to Ollama at {$baseUrl}";
        if ($reason !== '') {
            $message .= ": {$reason}";
        }

        return new self($message);
    }

    public static function requestFailed(string $endpoint, int $statusCode, string $body = ''): self
    {
        $message = "Ollama API request to {$endpoint} failed with HTTP {$statusCode}";
        if ($body !== '') {
            $decoded = json_decode($body, true);
            $detail = is_array($decoded) ? ($decoded['error'] ?? $body) : $body;
            $message .= ": {$detail}";
        }

        return new self($message);
    }

    public static function modelNotFound(string $model): self
    {
        return new self("Model '{$model}' not found.");
    }

    public static function invalidModelName(string $model): self
    {
        return new self(
            "Invalid model name '{$model}'. Model names may only contain alphanumeric characters, "
            . "colons, slashes, hyphens, dots, and underscores (e.g. 'llama3.2', 'namespace/model:tag').",
        );
    }
}
