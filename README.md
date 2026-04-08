# coqui-toolkit-ollama

Ollama model management toolkit for [Coqui](https://github.com/carmelosantana/coqui). Provides a single `ollama` tool with 11 actions for managing local Ollama models, searching the registry, generating embeddings, and more.

## Requirements

- PHP 8.4+
- [Ollama](https://ollama.com) running locally or accessible via network
- Coqui with `carmelosantana/php-agents ^0.7`

## Installation

```bash
composer require coquibot/coqui-toolkit-ollama
```

The toolkit is auto-discovered by Coqui on startup — no configuration needed.

## Configuration

By default, the toolkit connects to `http://localhost:11434`. To use a different Ollama server, set the `OLLAMA_HOST` credential:

```
credentials(action: "set", key: "OLLAMA_HOST", value: "http://my-server:11434")
```

Or set it in your environment before starting Coqui:

```bash
export OLLAMA_HOST=http://my-server:11434
```

## Actions

| Action | Description | Required Parameters |
|--------|-------------|-------------------|
| `list` | List all locally installed models | — |
| `show` | Show detailed model information | `model` |
| `pull` | Download a model from the registry | `model` |
| `delete` | Delete a local model (gated) | `model` |
| `copy` | Copy a model to a new name | `model`, `destination` |
| `create` | Create a custom model (gated) | `model` |
| `running` | List models loaded in memory | — |
| `search` | Search the Ollama registry | `query` |
| `embed` | Generate embeddings | `model`, `input` |
| `version` | Show Ollama server version | — |
| `update_all` | Pull latest for all installed models | — |

## Usage Examples

### List installed models

```
ollama(action: "list")
```

### Search and pull a model

```
ollama(action: "search", query: "code assistant")
ollama(action: "pull", model: "qwen2.5-coder:7b")
```

### Background pull (recommended for large models)

```
start_background_tool(
    tool_name: "ollama",
    arguments: '{"action": "pull", "model": "llama3.3:70b"}',
    title: "Pulling llama3.3 70B"
)
```

### Create a custom model

```
ollama(action: "create", model: "my-assistant", from: "llama3.3", system: "You are a concise technical writer.")
```

### Update all models in the background

```
start_background_tool(
    tool_name: "ollama",
    arguments: '{"action": "update_all"}',
    title: "Updating all Ollama models"
)
```

### Generate embeddings

```
ollama(action: "embed", model: "nomic-embed-text", input: "Hello world")
```

## Tool Gating

The `delete` and `create` actions require user confirmation before executing. This can be bypassed with the `--auto-approve` flag.

## Development

```bash
# Install dependencies
composer install

# Run tests
composer test

# Run static analysis
composer analyse
```

## License

MIT
