<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitOllama;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\BoolParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\ToolResult;

/**
 * Single tool with action enum for managing Ollama models.
 *
 * Follows the ComposerTool pattern: one tool, many actions, per-action validation.
 */
final class OllamaTool implements ToolInterface
{
    private ?OllamaClient $client = null;

    public function __construct(
        private readonly ?OllamaClient $injectedClient = null,
    ) {}

    public function name(): string
    {
        return 'ollama';
    }

    public function description(): string
    {
        return <<<'DESC'
            Manage Ollama models on the local server.

            Available actions:
            - list: List all locally installed models
            - show: Show detailed information about a model
            - pull: Download a model from the Ollama registry
            - delete: Delete a locally installed model (requires confirmation)
            - copy: Copy a model to a new name
            - create: Create a custom model (requires confirmation)
            - running: List models currently loaded in memory
            - search: Search the Ollama registry for models
            - embed: Generate embeddings from a model
            - version: Show the Ollama server version
            - update_all: Pull latest versions of all installed models

            **Tip:** For long-running operations (pull, update_all, create), use
            `start_background_tool` to run them asynchronously:
            ```
            start_background_tool(tool_name: "ollama", arguments: '{"action": "pull", "model": "llama3.3"}', title: "Pull llama3.3")
            ```
            DESC;
    }

    public function parameters(): array
    {
        return [
            new EnumParameter(
                name: 'action',
                description: 'The ollama action to perform',
                values: ['list', 'show', 'pull', 'delete', 'copy', 'create', 'running', 'search', 'embed', 'version', 'update_all'],
                required: true,
            ),
            new StringParameter(
                name: 'model',
                description: 'Model name (e.g. "llama3.3", "qwen2:7b"). Required for: show, pull, delete, copy (source), create, embed.',
                required: false,
            ),
            new StringParameter(
                name: 'destination',
                description: 'Destination model name. Required for copy action.',
                required: false,
            ),
            new StringParameter(
                name: 'query',
                description: 'Search query. Required for search action.',
                required: false,
            ),
            new StringParameter(
                name: 'input',
                description: 'Text input for embedding. Required for embed action.',
                required: false,
            ),
            new StringParameter(
                name: 'from',
                description: 'Base model to create from. Used with create action.',
                required: false,
            ),
            new StringParameter(
                name: 'system',
                description: 'System prompt for the new model. Used with create action.',
                required: false,
            ),
            new StringParameter(
                name: 'quantize',
                description: 'Quantization level (e.g. "q4_0", "q8_0"). Used with create action.',
                required: false,
            ),
            new BoolParameter(
                name: 'verbose',
                description: 'Show verbose output. Used with show action. Default: false.',
                required: false,
            ),
        ];
    }

    public function execute(array $input): ToolResult
    {
        $action = $input['action'] ?? '';

        $client = $this->resolveClient();

        // Check server availability for actions that need it
        if ($action !== 'search' && $action !== 'version') {
            if (!$client->isAvailable()) {
                return ToolResult::error(
                    "Ollama server is not reachable. Make sure Ollama is running.\n"
                    . "Start it with: `ollama serve` or check that the OLLAMA_HOST is configured correctly.",
                );
            }
        }

        try {
            return match ($action) {
                'list' => $this->listModels($client),
                'show' => $this->showModel($client, $input),
                'pull' => $this->pullModel($client, $input),
                'delete' => $this->deleteModel($client, $input),
                'copy' => $this->copyModel($client, $input),
                'create' => $this->createModel($client, $input),
                'running' => $this->runningModels($client),
                'search' => $this->searchModels($client, $input),
                'embed' => $this->embedText($client, $input),
                'version' => $this->showVersion($client),
                'update_all' => $this->updateAll($client),
                default => ToolResult::error("Unknown action: {$action}"),
            };
        } catch (Exception\OllamaException $e) {
            return ToolResult::error($e->getMessage());
        }
    }

    public function toFunctionSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => $this->description(),
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'action' => [
                            'type' => 'string',
                            'description' => 'The ollama action to perform',
                            'enum' => ['list', 'show', 'pull', 'delete', 'copy', 'create', 'running', 'search', 'embed', 'version', 'update_all'],
                        ],
                        'model' => [
                            'type' => 'string',
                            'description' => 'Model name (e.g. "llama3.3", "qwen2:7b"). Required for: show, pull, delete, copy (source), create, embed.',
                        ],
                        'destination' => [
                            'type' => 'string',
                            'description' => 'Destination model name. Required for copy action.',
                        ],
                        'query' => [
                            'type' => 'string',
                            'description' => 'Search query. Required for search action.',
                        ],
                        'input' => [
                            'type' => 'string',
                            'description' => 'Text input for embedding. Required for embed action.',
                        ],
                        'from' => [
                            'type' => 'string',
                            'description' => 'Base model to create from. Used with create action.',
                        ],
                        'system' => [
                            'type' => 'string',
                            'description' => 'System prompt for the new model. Used with create action.',
                        ],
                        'quantize' => [
                            'type' => 'string',
                            'description' => 'Quantization level (e.g. "q4_0", "q8_0"). Used with create action.',
                        ],
                        'verbose' => [
                            'type' => 'boolean',
                            'description' => 'Show verbose output. Used with show action. Default: false.',
                        ],
                    ],
                    'required' => ['action'],
                ],
            ],
        ];
    }

    private function listModels(OllamaClient $client): ToolResult
    {
        $data = $client->listModels();
        $models = $data['models'] ?? [];

        if ($models === []) {
            return ToolResult::success("No models installed. Use `ollama(action: \"pull\", model: \"llama3.3\")` to download one.");
        }

        $output = "## Installed Models\n\n";
        $output .= "| Model | Size | Modified | Format |\n";
        $output .= "|-------|------|----------|--------|\n";

        foreach ($models as $model) {
            $name = $model['name'] ?? 'unknown';
            $size = $this->formatBytes((int) ($model['size'] ?? 0));
            $modified = isset($model['modified_at'])
                ? date('Y-m-d H:i', strtotime($model['modified_at']))
                : '—';
            $format = $model['details']['quantization_level'] ?? $model['details']['format'] ?? '—';
            $output .= "| {$name} | {$size} | {$modified} | {$format} |\n";
        }

        $output .= "\n**Total:** " . count($models) . " model(s)";

        return ToolResult::success($output);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function showModel(OllamaClient $client, array $input): ToolResult
    {
        $model = $input['model'] ?? '';
        if ($model === '') {
            return ToolResult::error('Model name is required for show action.');
        }

        $verbose = (bool) ($input['verbose'] ?? false);
        $data = $client->show($model, $verbose);

        $output = "## Model: {$model}\n\n";

        if (isset($data['details'])) {
            $details = $data['details'];
            $output .= "| Property | Value |\n";
            $output .= "|----------|-------|\n";
            $output .= "| Family | " . ($details['family'] ?? '—') . " |\n";
            $output .= "| Parameter Size | " . ($details['parameter_size'] ?? '—') . " |\n";
            $output .= "| Quantization | " . ($details['quantization_level'] ?? '—') . " |\n";
            $output .= "| Format | " . ($details['format'] ?? '—') . " |\n";
        }

        if (isset($data['model_info'])) {
            $output .= "\n### Model Info\n\n";
            foreach ($data['model_info'] as $key => $value) {
                if (is_scalar($value)) {
                    $output .= "- **{$key}:** {$value}\n";
                }
            }
        }

        if (isset($data['system']) && $data['system'] !== '') {
            $output .= "\n### System Prompt\n\n";
            $output .= "```\n{$data['system']}\n```\n";
        }

        if (isset($data['license']) && $data['license'] !== '') {
            $license = $data['license'];
            if (strlen($license) > 500) {
                $license = substr($license, 0, 497) . '...';
            }
            $output .= "\n### License\n\n{$license}\n";
        }

        return ToolResult::success($output);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function pullModel(OllamaClient $client, array $input): ToolResult
    {
        $model = $input['model'] ?? '';
        if ($model === '') {
            return ToolResult::error('Model name is required for pull action.');
        }

        $data = $client->pull($model);
        $status = $data['status'] ?? 'completed';

        return ToolResult::success("## Pull Complete\n\n**Model:** {$model}\n**Status:** {$status}");
    }

    /**
     * @param array<string, mixed> $input
     */
    private function deleteModel(OllamaClient $client, array $input): ToolResult
    {
        $model = $input['model'] ?? '';
        if ($model === '') {
            return ToolResult::error('Model name is required for delete action.');
        }

        $client->delete($model);

        return ToolResult::success("## Model Deleted\n\n**Model:** {$model}\n\nThe model has been removed from the local Ollama server.");
    }

    /**
     * @param array<string, mixed> $input
     */
    private function copyModel(OllamaClient $client, array $input): ToolResult
    {
        $source = $input['model'] ?? '';
        $destination = $input['destination'] ?? '';

        if ($source === '') {
            return ToolResult::error('Model name (source) is required for copy action.');
        }
        if ($destination === '') {
            return ToolResult::error('Destination name is required for copy action.');
        }

        $client->copy($source, $destination);

        return ToolResult::success("## Model Copied\n\n**Source:** {$source}\n**Destination:** {$destination}");
    }

    /**
     * @param array<string, mixed> $input
     */
    private function createModel(OllamaClient $client, array $input): ToolResult
    {
        $model = $input['model'] ?? '';
        if ($model === '') {
            return ToolResult::error('Model name is required for create action.');
        }

        $data = $client->create(
            model: $model,
            from: $input['from'] ?? null,
            system: $input['system'] ?? null,
            quantize: $input['quantize'] ?? null,
        );

        $status = $data['status'] ?? 'success';

        return ToolResult::success("## Model Created\n\n**Model:** {$model}\n**Status:** {$status}");
    }

    private function runningModels(OllamaClient $client): ToolResult
    {
        $data = $client->running();
        $models = $data['models'] ?? [];

        if ($models === []) {
            return ToolResult::success('No models currently loaded in memory.');
        }

        $output = "## Running Models\n\n";
        $output .= "| Model | Size | VRAM | Expires |\n";
        $output .= "|-------|------|------|--------|\n";

        foreach ($models as $model) {
            $name = $model['name'] ?? 'unknown';
            $size = $this->formatBytes((int) ($model['size'] ?? 0));
            $vram = $this->formatBytes((int) ($model['size_vram'] ?? 0));
            $expires = $model['expires_at'] ?? '—';
            if ($expires !== '—') {
                $expires = date('Y-m-d H:i', strtotime($expires));
            }
            $output .= "| {$name} | {$size} | {$vram} | {$expires} |\n";
        }

        return ToolResult::success($output);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function searchModels(OllamaClient $client, array $input): ToolResult
    {
        $query = $input['query'] ?? '';
        if ($query === '') {
            return ToolResult::error('Search query is required for search action.');
        }

        $results = $client->searchRegistry($query);

        if ($results === []) {
            return ToolResult::success("No models found matching \"{$query}\".");
        }

        $output = "## Search Results: \"{$query}\"\n\n";
        $output .= "| Model | Description |\n";
        $output .= "|-------|-------------|\n";

        foreach (array_slice($results, 0, 25) as $result) {
            $name = $result['name'] ?? '';
            $desc = $result['description'] ?? '';
            if (strlen($desc) > 80) {
                $desc = substr($desc, 0, 77) . '...';
            }
            $output .= "| {$name} | {$desc} |\n";
        }

        if (count($results) > 25) {
            $output .= "\n*Showing 25 of " . count($results) . " results.*";
        }

        return ToolResult::success($output);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function embedText(OllamaClient $client, array $input): ToolResult
    {
        $model = $input['model'] ?? '';
        $text = $input['input'] ?? '';

        if ($model === '') {
            return ToolResult::error('Model name is required for embed action.');
        }
        if ($text === '') {
            return ToolResult::error('Input text is required for embed action.');
        }

        $data = $client->embed($model, $text);

        $embeddings = $data['embeddings'] ?? [];
        $firstEmbedding = isset($embeddings[0]) && is_array($embeddings[0]) ? $embeddings[0] : [];
        $dimensions = count($firstEmbedding);

        $output = "## Embeddings Generated\n\n";
        $output .= "**Model:** {$model}\n";
        $output .= "**Dimensions:** {$dimensions}\n";
        $output .= "**Vectors:** " . count($embeddings) . "\n";

        // Show first few values as a sample
        if ($firstEmbedding !== []) {
            $sample = array_slice($firstEmbedding, 0, 5);
            $output .= "**Sample (first 5):** [" . implode(', ', array_map(fn($v) => round((float) $v, 6), $sample)) . ", ...]\n";
        }

        return ToolResult::success($output);
    }

    private function showVersion(OllamaClient $client): ToolResult
    {
        $version = $client->version();

        return ToolResult::success("Ollama version: {$version}");
    }

    private function updateAll(OllamaClient $client): ToolResult
    {
        $data = $client->listModels();
        $models = $data['models'] ?? [];

        if ($models === []) {
            return ToolResult::success('No models installed to update.');
        }

        $output = "## Update All Models\n\n";
        $updated = 0;
        $failed = 0;

        foreach ($models as $model) {
            $name = $model['name'] ?? '';
            if ($name === '') {
                continue;
            }

            try {
                $result = $client->pull($name);
                $status = $result['status'] ?? 'success';
                $output .= "- **{$name}**: {$status}\n";
                $updated++;
            } catch (\Throwable $e) {
                $output .= "- **{$name}**: ❌ {$e->getMessage()}\n";
                $failed++;
            }
        }

        $output .= "\n**Updated:** {$updated} | **Failed:** {$failed}";

        return $failed > 0
            ? ToolResult::error($output)
            : ToolResult::success($output);
    }

    private function resolveClient(): OllamaClient
    {
        if ($this->injectedClient !== null) {
            return $this->injectedClient;
        }

        return $this->client ??= OllamaClient::fromEnv();
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $exponent = (int) floor(log($bytes, 1024));
        $value = $bytes / (1024 ** $exponent);

        return round($value, 1) . ' ' . $units[$exponent];
    }
}
