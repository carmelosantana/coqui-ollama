<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitOllama;

use CarmeloSantana\CoquiToolkitOllama\Exception\OllamaException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Stateless HTTP client wrapping the Ollama REST API.
 *
 * All mutation endpoints use stream: false for background tool compatibility.
 *
 * @see https://github.com/ollama/ollama/blob/main/docs/api.md
 */
final class OllamaClient
{
    private const string DEFAULT_BASE_URL = 'http://localhost:11434';
    private const string MODEL_NAME_PATTERN = '/^[a-zA-Z0-9][a-zA-Z0-9._\-\/:]*(\/[a-zA-Z0-9._\-:]+)?$/';
    private const int DEFAULT_TIMEOUT = 600;
    private const int CONNECT_TIMEOUT = 5;

    private HttpClientInterface $httpClient;

    public function __construct(
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? HttpClient::create([
            'timeout' => self::DEFAULT_TIMEOUT,
        ]);
    }

    /**
     * Factory — reads OLLAMA_HOST from environment, strips /v1 suffix if present.
     */
    public static function fromEnv(): self
    {
        $host = getenv('OLLAMA_HOST');
        $baseUrl = self::DEFAULT_BASE_URL;

        if (is_string($host) && $host !== '') {
            $baseUrl = rtrim($host, '/');
            // php-agents OllamaProvider appends /v1 — strip it for native API
            if (str_ends_with($baseUrl, '/v1')) {
                $baseUrl = substr($baseUrl, 0, -3);
            }
        }

        return new self(baseUrl: $baseUrl);
    }

    /**
     * List locally installed models.
     *
     * @return array<string, mixed>
     */
    public function listModels(): array
    {
        return $this->get('/api/tags');
    }

    /**
     * Show detailed information about a model.
     *
     * @return array<string, mixed>
     */
    public function show(string $model, bool $verbose = false): array
    {
        $this->validateModelName($model);

        $body = ['model' => $model];
        if ($verbose) {
            $body['verbose'] = true;
        }

        return $this->post('/api/show', $body);
    }

    /**
     * Pull a model from the Ollama registry (non-streaming).
     *
     * @return array<string, mixed>
     */
    public function pull(string $model): array
    {
        $this->validateModelName($model);

        return $this->post('/api/pull', [
            'model' => $model,
            'stream' => false,
        ]);
    }

    /**
     * Delete a locally installed model.
     */
    public function delete(string $model): bool
    {
        $this->validateModelName($model);

        try {
            $this->httpClient->request('DELETE', $this->baseUrl . '/api/delete', [
                'json' => ['model' => $model],
            ])->getContent();

            return true;
        } catch (HttpExceptionInterface $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            if ($statusCode === 404) {
                throw OllamaException::modelNotFound($model);
            }
            throw OllamaException::requestFailed('/api/delete', $statusCode, $this->extractBody($e));
        }
    }

    /**
     * Copy a model to a new name.
     */
    public function copy(string $source, string $destination): bool
    {
        $this->validateModelName($source);
        $this->validateModelName($destination);

        try {
            $this->httpClient->request('POST', $this->baseUrl . '/api/copy', [
                'json' => [
                    'source' => $source,
                    'destination' => $destination,
                ],
            ])->getContent();

            return true;
        } catch (HttpExceptionInterface $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            if ($statusCode === 404) {
                throw OllamaException::modelNotFound($source);
            }
            throw OllamaException::requestFailed('/api/copy', $statusCode, $this->extractBody($e));
        }
    }

    /**
     * Create a model (non-streaming).
     *
     * @return array<string, mixed>
     */
    public function create(
        string $model,
        ?string $from = null,
        ?string $system = null,
        ?string $template = null,
        ?string $parameters = null,
        ?string $quantize = null,
    ): array {
        $this->validateModelName($model);

        $body = ['model' => $model, 'stream' => false];

        if ($from !== null && $from !== '') {
            $this->validateModelName($from);
            $body['from'] = $from;
        }
        if ($system !== null && $system !== '') {
            $body['system'] = $system;
        }
        if ($template !== null && $template !== '') {
            $body['template'] = $template;
        }
        if ($parameters !== null && $parameters !== '') {
            $body['parameters'] = $parameters;
        }
        if ($quantize !== null && $quantize !== '') {
            $body['quantize'] = $quantize;
        }

        return $this->post('/api/create', $body);
    }

    /**
     * List models currently loaded in memory.
     *
     * @return array<string, mixed>
     */
    public function running(): array
    {
        return $this->get('/api/ps');
    }

    /**
     * Generate embeddings from a model.
     *
     * @param string|array<int, string> $input
     * @return array<string, mixed>
     */
    public function embed(string $model, string|array $input): array
    {
        $this->validateModelName($model);

        return $this->post('/api/embed', [
            'model' => $model,
            'input' => $input,
        ]);
    }

    /**
     * Get the Ollama server version.
     */
    public function version(): string
    {
        $data = $this->get('/api/version');

        return $data['version'] ?? 'unknown';
    }

    /**
     * Check if the Ollama server is reachable.
     */
    public function isAvailable(): bool
    {
        try {
            $this->httpClient->request('GET', $this->baseUrl . '/', [
                'timeout' => self::CONNECT_TIMEOUT,
            ])->getContent();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Search the Ollama registry for models.
     *
     * Tries the ollama.com search endpoint first.
     * Falls back to the `ollama search` CLI command if available.
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchRegistry(string $query): array
    {
        if (trim($query) === '') {
            return [];
        }

        // Try HTTP search first
        $results = $this->searchViaHttp($query);
        if ($results !== null) {
            return $results;
        }

        // Fall back to CLI
        return $this->searchViaCli($query);
    }

    /**
     * @return array<int, array<string, mixed>>|null null if endpoint unavailable
     */
    private function searchViaHttp(string $query): ?array
    {
        try {
            $response = $this->httpClient->request('GET', 'https://ollama.com/api/search', [
                'query' => ['q' => $query],
                'timeout' => 10,
            ]);

            $data = $response->toArray();
            $models = $data['models'] ?? $data['results'] ?? [];

            if (!is_array($models)) {
                return null;
            }

            $results = [];
            foreach ($models as $model) {
                if (!is_array($model)) {
                    continue;
                }
                $results[] = [
                    'name' => $model['name'] ?? '',
                    'description' => $model['description'] ?? '',
                    'tags' => $model['tags'] ?? [],
                    'pulls' => $model['pull_count'] ?? $model['pulls'] ?? 0,
                ];
            }

            return $results;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function searchViaCli(string $query): array
    {
        $escapedQuery = escapeshellarg($query);
        $command = "ollama search {$escapedQuery} 2>/dev/null";

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            return [];
        }

        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0 || trim($stdout) === '') {
            return [];
        }

        return $this->parseCliSearchOutput($stdout);
    }

    /**
     * Parse the tabular output of `ollama search`.
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseCliSearchOutput(string $output): array
    {
        $lines = explode("\n", trim($output));
        $results = [];

        // Skip header line
        foreach (array_slice($lines, 1) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // Output is tab/space-separated: NAME DESCRIPTION
            $parts = preg_split('/\s{2,}|\t+/', $line, 2);
            if ($parts === false) {
                continue;
            }

            $results[] = [
                'name' => trim($parts[0]),
                'description' => isset($parts[1]) ? trim($parts[1]) : '',
                'tags' => [],
                'pulls' => 0,
            ];
        }

        return $results;
    }

    /**
     * Validate that a model name contains only safe characters.
     */
    private function validateModelName(string $model): void
    {
        if ($model === '' || preg_match(self::MODEL_NAME_PATTERN, $model) !== 1) {
            throw OllamaException::invalidModelName($model);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $endpoint): array
    {
        try {
            $response = $this->httpClient->request('GET', $this->baseUrl . $endpoint);

            return $response->toArray();
        } catch (HttpExceptionInterface $e) {
            throw OllamaException::requestFailed(
                $endpoint,
                $e->getResponse()->getStatusCode(),
                $this->extractBody($e),
            );
        } catch (\Throwable $e) {
            throw OllamaException::connectionFailed($this->baseUrl, $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function post(string $endpoint, array $body): array
    {
        try {
            $response = $this->httpClient->request('POST', $this->baseUrl . $endpoint, [
                'json' => $body,
            ]);

            return $response->toArray();
        } catch (HttpExceptionInterface $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            if ($statusCode === 404) {
                $model = $body['model'] ?? 'unknown';
                throw OllamaException::modelNotFound($model);
            }
            throw OllamaException::requestFailed($endpoint, $statusCode, $this->extractBody($e));
        } catch (\Throwable $e) {
            throw OllamaException::connectionFailed($this->baseUrl, $e->getMessage());
        }
    }

    private function extractBody(HttpExceptionInterface $e): string
    {
        try {
            return $e->getResponse()->getContent(false);
        } catch (\Throwable) {
            return '';
        }
    }
}
