<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitOllama;

use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;

/**
 * Toolkit providing Ollama model management for Coqui.
 *
 * Auto-discovered by Coqui's ToolkitDiscovery when installed via Composer.
 * Exposes a single `ollama` tool with an action enum for all operations.
 */
final class OllamaToolkit implements ToolkitInterface
{
    public function __construct(
        private readonly ?OllamaClient $client = null,
    ) {}

    /**
     * Factory method for ToolkitDiscovery — reads OLLAMA_HOST from environment.
     */
    public static function fromEnv(): self
    {
        return new self(client: OllamaClient::fromEnv());
    }

    public function tools(): array
    {
        return [
            new OllamaTool(injectedClient: $this->client),
        ];
    }

    public function guidelines(): string
    {
        return <<<'GUIDELINES'
            <OLLAMA-TOOLKIT-GUIDELINES>
            Use the `ollama` tool to manage local Ollama models.

            Common workflows:
            1. `ollama(action: "list")` — see what's installed
            2. `ollama(action: "search", query: "code")` — find models on the registry
            3. `ollama(action: "pull", model: "llama3.3")` — download a model
            4. `ollama(action: "show", model: "llama3.3")` — inspect model details
            5. `ollama(action: "delete", model: "old-model")` — remove unused models

            For long-running operations (pull, update_all, create), prefer using
            `start_background_tool` to avoid blocking the conversation:

            ```
            start_background_tool(
                tool_name: "ollama",
                arguments: '{"action": "pull", "model": "llama3.3"}',
                title: "Pulling llama3.3"
            )
            ```

            Then check progress with `task_status(task_id: <id>)`.

            Key behaviors:
            - delete and create actions require user confirmation (gated)
            - Model names support tags: "model:tag" (e.g. "llama3.3:70b")
            - The search action queries the Ollama registry for available models
            - update_all pulls latest versions of all installed models
            </OLLAMA-TOOLKIT-GUIDELINES>
            GUIDELINES;
    }
}
