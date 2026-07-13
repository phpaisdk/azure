# aisdk/azure

Official Azure OpenAI provider for the framework-agnostic PHP AI SDK, with text, streaming, image, speech, transcription, and embedding generation.

## Installation

```bash
composer require aisdk/azure
```

## Basic Usage

```php
use AiSdk\Azure;
use AiSdk\Generate;

$result = Generate::text()
    ->model(Azure::model('gpt-4o'))
    ->instructions('Write short, clear answers.')
    ->prompt('Explain closures in PHP.')
    ->run();

echo $result->text();
```

The identifier passed to `Azure::model()`, `Azure::image()`, `Azure::speech()`, `Azure::transcription()`, or `Azure::embedding()` is the Azure deployment name. It does not have to match the underlying model name.

Deployment names pass through unchanged and do not need to be registered. This package does not ship a model inventory; the SDK performs internal adapter validation before Azure validates support for the selected deployment.

## Image and speech generation

```php
$image = Generate::image('A product photo on a white background')
    ->model(Azure::image('my-image-deployment'))
    ->size('1024x1024')
    ->run();

$speech = Generate::speech('Welcome to our application.')
    ->model(Azure::speech('my-speech-deployment'))
    ->voice('alloy')
    ->run();
```

## Transcription

```php
use AiSdk\Azure;
use AiSdk\Content;
use AiSdk\Generate;

$result = Generate::transcription(Content::audio(__DIR__.'/meeting.mp3'))
    ->model(Azure::transcription('my-transcription-deployment'))
    ->run();

echo $result->output->text;
```

The default Azure v1 surface uses `/openai/v1/audio/transcriptions`. With `useDeploymentBasedUrls`, the adapter uses the classic deployment URL and configured API version.

## Embeddings

```php
use AiSdk\Azure;
use AiSdk\Generate;

$result = Generate::embedding(['Search query', 'Document text'])
    ->model(Azure::embedding('my-embedding-deployment'))
    ->dimensions(512)
    ->providerOptions('azure', ['user' => 'user-123'])
    ->run();

$vector = $result->output->vector;
```

The default Azure `/openai/v1/embeddings` endpoint sends the deployment name in the request's `model` field. When `useDeploymentBasedUrls` is enabled, the same identifier is placed in the classic `/deployments/{deployment}/embeddings` URL.

## Streaming

```php
use AiSdk\Azure;
use AiSdk\Generate;

foreach (Generate::text('Tell me a story.')->model(Azure::model('gpt-4o'))->stream()->chunks() as $chunk) {
    echo $chunk;
}
```

## Text API surface

Chat Completions remains the default. Azure's v1 Responses API can be selected for a provider instance or an individual request:

```php
Azure::create([
    'apiKey' => 'azure-...',
    'resourceName' => 'my-resource',
    'api' => 'responses',
]);

$result = Generate::text('Explain this code.')
    ->model(Azure::model('my-model-deployment'))
    ->providerOptions('azure', ['api' => 'responses'])
    ->run();
```

Supported values are `chat_completions` and `responses`. Responses requires the current `/openai/v1` endpoint and is intentionally unavailable when `useDeploymentBasedUrls` is enabled.

## Configuration

Azure OpenAI resolves the endpoint from either a resource name or an explicit base URL.

| Variable | Description | Default |
|---|---|---|
| `AZURE_OPENAI_API_KEY` | API key authentication | — |
| `AZURE_OPENAI_AUTH_TOKEN` / `AZURE_OPENAI_AD_TOKEN` | Microsoft Entra ID bearer token | — |
| `AZURE_RESOURCE_NAME` | Azure OpenAI resource name | — |
| `AZURE_OPENAI_BASE_URL` | Resource endpoint (`https://{resource}.openai.azure.com`); `/openai` and `/openai/v1` suffixes are normalized | — |

```php
Azure::create([
    'apiKey' => 'azure-...',
    'resourceName' => 'my-resource',
    'apiVersion' => '2024-10-21', // Used only by classic deployment URLs.
    // Set to true to use classic deployment-based URLs.
    'useDeploymentBasedUrls' => false,
]);
```

## Authentication

Azure OpenAI accepts an API key **or** Microsoft Entra ID (Azure AD) where the selected endpoint supports it. Microsoft's current embeddings guide documents API-key authentication for the Azure `/openai/v1/embeddings` endpoint, so configure `apiKey` for those requests.

```php
// API key
Azure::create(['apiKey' => 'azure-...', 'resourceName' => 'my-resource']);

// Static Entra ID token
Azure::create(['entraToken' => $token, 'resourceName' => 'my-resource']);

// Entra ID token provider (refreshed per request) — plug in MSAL / azure-identity
Azure::create([
    'resourceName' => 'my-resource',
    'tokenProvider' => fn (): string => $credential->getToken('https://ai.azure.com/.default'),
]);
```

The current Azure `/openai/v1` API is the default and does not send an `api-version` query parameter. Set `useDeploymentBasedUrls` to `true` only for a classic deployment URL that still requires one.

## Reasoning

```php
use AiSdk\Reasoning;

$result = Generate::text('Explain the tradeoff.')
    ->model(Azure::model('o3-mini'))
    ->reasoning(Reasoning::effort('low'))
    ->run();
```

## Testing

```bash
composer test
```

## Links

- [Azure OpenAI Embeddings](https://learn.microsoft.com/en-us/azure/foundry/openai/how-to/embeddings)
- [Azure OpenAI Responses API](https://learn.microsoft.com/en-us/azure/foundry/openai/how-to/responses)
- [Azure OpenAI Transcriptions API](https://learn.microsoft.com/en-us/azure/foundry/openai/reference#transcriptions---create)
- [Core Package](https://github.com/phpaisdk/core)
