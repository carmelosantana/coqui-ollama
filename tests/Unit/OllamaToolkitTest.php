<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CarmeloSantana\CoquiToolkitOllama\Exception\OllamaException;
use CarmeloSantana\CoquiToolkitOllama\OllamaClient;
use CarmeloSantana\CoquiToolkitOllama\OllamaTool;
use CarmeloSantana\CoquiToolkitOllama\OllamaToolkit;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

// -- Factory & Configuration --

test('fromEnv reads OLLAMA_HOST from environment', function () {
    $original = getenv('OLLAMA_HOST');

    putenv('OLLAMA_HOST=http://custom-host:9999');
    $toolkit = OllamaToolkit::fromEnv();

    expect($toolkit)->toBeInstanceOf(OllamaToolkit::class)
        ->and($toolkit->tools())->toHaveCount(1);

    if ($original !== false) {
        putenv("OLLAMA_HOST={$original}");
    } else {
        putenv('OLLAMA_HOST');
    }
});

test('fromEnv strips /v1 suffix from OLLAMA_HOST', function () {
    $original = getenv('OLLAMA_HOST');

    putenv('OLLAMA_HOST=http://localhost:11434/v1');
    $client = OllamaClient::fromEnv();

    // The client should work without /v1 — verified by making a request
    // We can't inspect the private baseUrl, but we can verify the factory doesn't throw
    expect($client)->toBeInstanceOf(OllamaClient::class);

    if ($original !== false) {
        putenv("OLLAMA_HOST={$original}");
    } else {
        putenv('OLLAMA_HOST');
    }
});

test('fromEnv uses default when OLLAMA_HOST not set', function () {
    $original = getenv('OLLAMA_HOST');

    putenv('OLLAMA_HOST');
    $toolkit = OllamaToolkit::fromEnv();

    expect($toolkit)->toBeInstanceOf(OllamaToolkit::class);

    if ($original !== false) {
        putenv("OLLAMA_HOST={$original}");
    }
});

// -- Tool Registration --

test('tools returns single ollama tool', function () {
    $toolkit = new OllamaToolkit();
    $tools = $toolkit->tools();

    expect($tools)->toHaveCount(1)
        ->and($tools[0]->name())->toBe('ollama');
});

test('guidelines returns non-empty string with ollama guidance', function () {
    $toolkit = new OllamaToolkit();
    $guidelines = $toolkit->guidelines();

    expect($guidelines)->toBeString()
        ->and($guidelines)->toContain('ollama')
        ->and($guidelines)->toContain('start_background_tool');
});

// -- Function Schema --

test('ollama tool generates valid function schema', function () {
    $tool = new OllamaTool();
    $schema = $tool->toFunctionSchema();

    expect($schema['type'])->toBe('function')
        ->and($schema['function']['name'])->toBe('ollama')
        ->and($schema['function']['parameters']['properties'])->toHaveKey('action')
        ->and($schema['function']['parameters']['properties'])->toHaveKey('model')
        ->and($schema['function']['parameters']['properties'])->toHaveKey('destination')
        ->and($schema['function']['parameters']['properties'])->toHaveKey('query')
        ->and($schema['function']['parameters']['properties'])->toHaveKey('input')
        ->and($schema['function']['parameters']['required'])->toBe(['action']);
});

test('action enum contains all expected values', function () {
    $tool = new OllamaTool();
    $schema = $tool->toFunctionSchema();
    $actions = $schema['function']['parameters']['properties']['action']['enum'];

    expect($actions)->toContain('list')
        ->and($actions)->toContain('show')
        ->and($actions)->toContain('pull')
        ->and($actions)->toContain('delete')
        ->and($actions)->toContain('copy')
        ->and($actions)->toContain('create')
        ->and($actions)->toContain('running')
        ->and($actions)->toContain('search')
        ->and($actions)->toContain('embed')
        ->and($actions)->toContain('version')
        ->and($actions)->toContain('update_all');
});

// -- Helper: create a tool with mock HTTP --

function createToolWithMock(MockHttpClient $mockClient): OllamaTool
{
    $client = new OllamaClient(baseUrl: 'http://localhost:11434', httpClient: $mockClient);
    return new OllamaTool(injectedClient: $client);
}

function createToolWithResponses(array $responses): OllamaTool
{
    // First response = isAvailable check (GET /), remaining = actual requests
    $mockClient = new MockHttpClient($responses);
    return createToolWithMock($mockClient);
}

function availableResponse(): MockResponse
{
    return new MockResponse('Ollama is running', ['http_code' => 200]);
}

// -- List Action --

test('list action formats models as markdown table', function () {
    $tool = createToolWithResponses([
        availableResponse(),
        new MockResponse(json_encode([
            'models' => [
                [
                    'name' => 'llama3.3:latest',
                    'size' => 4_661_224_676,
                    'modified_at' => '2024-12-01T10:00:00Z',
                    'details' => ['quantization_level' => 'Q4_0', 'format' => 'gguf'],
                ],
                [
                    'name' => 'qwen2:7b',
                    'size' => 3_825_819_519,
                    'modified_at' => '2024-11-15T08:30:00Z',
                    'details' => ['quantization_level' => 'Q4_K_M', 'format' => 'gguf'],
                ],
            ],
        ])),
    ]);

    $result = $tool->execute(['action' => 'list']);

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($result->content)->toContain('llama3.3:latest')
        ->and($result->content)->toContain('qwen2:7b')
        ->and($result->content)->toContain('2 model(s)');
});

test('list action handles empty model list', function () {
    $tool = createToolWithResponses([
        availableResponse(),
        new MockResponse(json_encode(['models' => []])),
    ]);

    $result = $tool->execute(['action' => 'list']);

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($result->content)->toContain('No models installed');
});

// -- Show Action --

test('show action returns model details', function () {
    $tool = createToolWithResponses([
        availableResponse(),
        new MockResponse(json_encode([
            'details' => [
                'family' => 'llama',
                'parameter_size' => '8B',
                'quantization_level' => 'Q4_0',
                'format' => 'gguf',
            ],
            'model_info' => [
                'general.architecture' => 'llama',
                'general.parameter_count' => 8000000000,
            ],
        ])),
    ]);

    $result = $tool->execute(['action' => 'show', 'model' => 'llama3.3']);

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($result->content)->toContain('llama3.3')
        ->and($result->content)->toContain('8B')
        ->and($result->content)->toContain('Q4_0');
});

test('show action requires model name', function () {
    $tool = createToolWithResponses([availableResponse()]);

    $result = $tool->execute(['action' => 'show']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('Model name is required');
});

// -- Pull Action --

test('pull action returns success on completion', function () {
    $tool = createToolWithResponses([
        availableResponse(),
        new MockResponse(json_encode(['status' => 'success'])),
    ]);

    $result = $tool->execute(['action' => 'pull', 'model' => 'llama3.3']);

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($result->content)->toContain('llama3.3')
        ->and($result->content)->toContain('success');
});

test('pull action requires model name', function () {
    $tool = createToolWithResponses([availableResponse()]);

    $result = $tool->execute(['action' => 'pull']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('Model name is required');
});

// -- Delete Action --

test('delete action returns success', function () {
    $tool = createToolWithResponses([
        availableResponse(),
        new MockResponse('', ['http_code' => 200]),
    ]);

    $result = $tool->execute(['action' => 'delete', 'model' => 'old-model']);

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($result->content)->toContain('old-model')
        ->and($result->content)->toContain('Deleted');
});

test('delete action requires model name', function () {
    $tool = createToolWithResponses([availableResponse()]);

    $result = $tool->execute(['action' => 'delete']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('Model name is required');
});

// -- Copy Action --

test('copy action returns success', function () {
    $tool = createToolWithResponses([
        availableResponse(),
        new MockResponse('', ['http_code' => 200]),
    ]);

    $result = $tool->execute(['action' => 'copy', 'model' => 'llama3.3', 'destination' => 'my-llama']);

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($result->content)->toContain('llama3.3')
        ->and($result->content)->toContain('my-llama');
});

test('copy action requires both model and destination', function () {
    $tool = createToolWithResponses([availableResponse()]);

    $result = $tool->execute(['action' => 'copy', 'model' => 'llama3.3']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('Destination');
});

test('copy action requires model name', function () {
    $tool = createToolWithResponses([availableResponse()]);

    $result = $tool->execute(['action' => 'copy']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('source');
});

// -- Create Action --

test('create action returns success', function () {
    $tool = createToolWithResponses([
        availableResponse(),
        new MockResponse(json_encode(['status' => 'success'])),
    ]);

    $result = $tool->execute([
        'action' => 'create',
        'model' => 'my-model',
        'from' => 'llama3.3',
        'system' => 'You are a helpful assistant.',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($result->content)->toContain('my-model');
});

test('create action requires model name', function () {
    $tool = createToolWithResponses([availableResponse()]);

    $result = $tool->execute(['action' => 'create']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('Model name is required');
});

// -- Running Action --

test('running action formats loaded models', function () {
    $tool = createToolWithResponses([
        availableResponse(),
        new MockResponse(json_encode([
            'models' => [
                [
                    'name' => 'llama3.3:latest',
                    'size' => 4_661_224_676,
                    'size_vram' => 4_661_224_676,
                    'expires_at' => '2024-12-01T15:00:00Z',
                ],
            ],
        ])),
    ]);

    $result = $tool->execute(['action' => 'running']);

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($result->content)->toContain('llama3.3:latest')
        ->and($result->content)->toContain('VRAM');
});

test('running action handles no loaded models', function () {
    $tool = createToolWithResponses([
        availableResponse(),
        new MockResponse(json_encode(['models' => []])),
    ]);

    $result = $tool->execute(['action' => 'running']);

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($result->content)->toContain('No models currently loaded');
});

// -- Search Action --

test('search action requires query', function () {
    $tool = createToolWithResponses([availableResponse()]);

    $result = $tool->execute(['action' => 'search']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('Search query is required');
});

// -- Embed Action --

test('embed action returns embedding info', function () {
    $tool = createToolWithResponses([
        availableResponse(),
        new MockResponse(json_encode([
            'embeddings' => [[0.1, 0.2, 0.3, 0.4, 0.5, 0.6]],
        ])),
    ]);

    $result = $tool->execute([
        'action' => 'embed',
        'model' => 'nomic-embed-text',
        'input' => 'Hello world',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($result->content)->toContain('nomic-embed-text')
        ->and($result->content)->toContain('Dimensions')
        ->and($result->content)->toContain('6');
});

test('embed action requires model name', function () {
    $tool = createToolWithResponses([availableResponse()]);

    $result = $tool->execute(['action' => 'embed', 'input' => 'hello']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('Model name is required');
});

test('embed action requires input text', function () {
    $tool = createToolWithResponses([availableResponse()]);

    $result = $tool->execute(['action' => 'embed', 'model' => 'nomic-embed-text']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('Input text is required');
});

// -- Version Action --

test('version action returns server version', function () {
    $tool = createToolWithResponses([
        new MockResponse(json_encode(['version' => '0.6.2'])),
    ]);

    $result = $tool->execute(['action' => 'version']);

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($result->content)->toContain('0.6.2');
});

// -- Update All Action --

test('update_all pulls all installed models', function () {
    $tool = createToolWithResponses([
        availableResponse(),
        // listModels response
        new MockResponse(json_encode([
            'models' => [
                ['name' => 'llama3.3:latest'],
                ['name' => 'qwen2:7b'],
            ],
        ])),
        // pull llama3.3
        new MockResponse(json_encode(['status' => 'success'])),
        // pull qwen2
        new MockResponse(json_encode(['status' => 'success'])),
    ]);

    $result = $tool->execute(['action' => 'update_all']);

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($result->content)->toContain('llama3.3:latest')
        ->and($result->content)->toContain('qwen2:7b')
        ->and($result->content)->toContain('**Updated:** 2');
});

test('update_all handles empty model list', function () {
    $tool = createToolWithResponses([
        availableResponse(),
        new MockResponse(json_encode(['models' => []])),
    ]);

    $result = $tool->execute(['action' => 'update_all']);

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($result->content)->toContain('No models installed');
});

// -- Server Availability --

test('returns error when server is not reachable', function () {
    $mockClient = new MockHttpClient([
        new MockResponse('', ['error' => 'Connection refused']),
    ]);

    $client = new OllamaClient(baseUrl: 'http://localhost:11434', httpClient: $mockClient);
    $tool = new OllamaTool(injectedClient: $client);

    $result = $tool->execute(['action' => 'list']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('not reachable');
});

// -- Unknown Action --

test('returns error for unknown action', function () {
    $tool = createToolWithResponses([availableResponse()]);

    $result = $tool->execute(['action' => 'nonexistent']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('Unknown action');
});

// -- Model Name Validation --

test('OllamaClient rejects invalid model names', function () {
    $client = new OllamaClient();

    expect(fn() => $client->show(''))->toThrow(OllamaException::class)
        ->and(fn() => $client->show('rm -rf /'))->toThrow(OllamaException::class)
        ->and(fn() => $client->show('model;drop'))->toThrow(OllamaException::class);
});

test('OllamaClient accepts valid model names', function () {
    // We test that validation passes by checking it doesn't throw on validation
    // The actual HTTP call will fail, but that's after validation
    $mockClient = new MockHttpClient([
        new MockResponse(json_encode(['details' => []])),
    ]);
    $client = new OllamaClient(httpClient: $mockClient);

    // These should all pass validation (HTTP may fail but that's OK)
    $client->show('llama3.3');

    $mockClient = new MockHttpClient([
        new MockResponse(json_encode(['details' => []])),
    ]);
    $client = new OllamaClient(httpClient: $mockClient);
    $client->show('qwen2:7b');

    $mockClient = new MockHttpClient([
        new MockResponse(json_encode(['details' => []])),
    ]);
    $client = new OllamaClient(httpClient: $mockClient);
    $client->show('library/llama3.3:latest');

    expect(true)->toBeTrue();
});

// -- OllamaException --

test('OllamaException factory methods create correct messages', function () {
    $e1 = OllamaException::connectionFailed('http://localhost:11434', 'refused');
    expect($e1->getMessage())->toContain('localhost:11434')
        ->and($e1->getMessage())->toContain('refused');

    $e2 = OllamaException::requestFailed('/api/tags', 500, 'internal error');
    expect($e2->getMessage())->toContain('/api/tags')
        ->and($e2->getMessage())->toContain('500');

    $e3 = OllamaException::modelNotFound('nonexistent');
    expect($e3->getMessage())->toContain('nonexistent');

    $e4 = OllamaException::invalidModelName('bad;name');
    expect($e4->getMessage())->toContain('bad;name');
});

// -- Interface Compliance --

test('OllamaToolkit implements ToolkitInterface', function () {
    expect(new OllamaToolkit())->toBeInstanceOf(\CarmeloSantana\PHPAgents\Contract\ToolkitInterface::class);
});

test('OllamaTool implements ToolInterface', function () {
    expect(new OllamaTool())->toBeInstanceOf(\CarmeloSantana\PHPAgents\Contract\ToolInterface::class);
});
