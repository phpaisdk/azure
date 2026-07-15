# aisdk/azure

<a href="https://github.com/phpaisdk/azure/actions"><img alt="GitHub Workflow Status" src="https://img.shields.io/github/actions/workflow/status/phpaisdk/azure/tests.yml?branch=main&label=Tests"></a>
<a href="https://packagist.org/packages/aisdk/azure"><img alt="Total Downloads" src="https://img.shields.io/packagist/dt/aisdk/azure"></a>
<a href="https://packagist.org/packages/aisdk/azure"><img alt="Latest Version" src="https://img.shields.io/packagist/v/aisdk/azure"></a>
<a href="https://packagist.org/packages/aisdk/azure"><img alt="License" src="https://img.shields.io/packagist/l/aisdk/azure"></a>
<a href="https://whyphp.dev"><img src="https://img.shields.io/badge/Why_PHP-in_2026-7A86E8?style=flat-square&labelColor=18181b" alt="Why PHP in 2026"></a>

------

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

The identifier passed to `Azure::model()` is the Azure deployment name. It does not have to match the underlying model name.

Deployment names pass through unchanged and do not need to be registered. This package does not ship a model inventory; the SDK performs internal adapter validation before Azure validates support for the selected deployment.

## Image and speech generation

```php
$image = Generate::image('A product photo on a white background')
    ->model(Azure::model('my-image-deployment'))
    ->size('1024x1024')
    ->run();

$speech = Generate::speech('Welcome to our application.')
    ->model(Azure::model('my-speech-deployment'))
    ->voice('alloy')
    ->run();
```

## Transcription

```php
use AiSdk\Azure;
use AiSdk\Content;
use AiSdk\Generate;

$result = Generate::transcription(Content::audio(__DIR__.'/meeting.mp3'))
    ->model(Azure::model('my-transcription-deployment'))
    ->run();

echo $result->output->text;
```

The default Azure v1 surface uses `/openai/v1/audio/transcriptions`. With `useDeploymentBasedUrls`, the adapter uses the classic deployment URL and configured API version.

## Live voice, transcription, and translation

Install `aisdk/transport` for ready-made WebSocket connections:

```bash
composer require aisdk/transport
```

```php
use AiSdk\Azure;
use AiSdk\Live;
use AiSdk\Live\AudioDelta;
use AiSdk\Live\TranscriptDelta;
use AiSdk\Transport;

$session = Live::voice()
    ->model(Azure::model('gpt-realtime'))
    ->instructions('You are a concise customer-support agent.')
    ->voice('marin')
    ->connect(Transport::auto());

$session->sendAudio($pcmBytes);

foreach ($session->events() as $event) {
    if ($event instanceof AudioDelta) {
        playAudio($event->bytes);
    }

    if ($event instanceof TranscriptDelta) {
        echo $event->delta;
    }
}
```

Send microphone audio and consume events concurrently in a long-running
application. Registered core tools with handlers are executed automatically;
unknown calls are emitted as `ToolCallEvent` values and can be answered with
`$session->sendToolResult($callId, $result)`.

Azure's dedicated realtime deployments map to separate builders and endpoints:

```php
$transcriber = Live::transcribe()
    ->model(Azure::model('gpt-realtime-whisper'))
    ->language('en')
    ->audioFormat('pcm16')
    ->connect(Transport::auto());

$translator = Live::translate()
    ->model(Azure::model('gpt-realtime-translate'))
    ->from('en')
    ->to('es')
    ->connect(Transport::auto());
```

The translation protocol streams audio continuously, so it intentionally does
not expose OpenAI-style commit, clear, response-create, or cancel operations.

### Core-only transport

`aisdk/transport` is optional. The same operations accept an application
implementation of `AiSdk\Live\Contracts\TransportInterface`:

```php
$session = Live::voice()
    ->model(Azure::model('gpt-realtime'))
    ->connect($appWebSocketTransport);
```

The [core custom-transport guide](https://github.com/phpaisdk/core#core-without-aisdktransport)
contains the complete WebSocket implementation. Azure authentication, endpoint
selection, session JSON, and event normalization remain in this package.

### Browser WebRTC

The backend can issue a short-lived credential or proxy the complete SDP
exchange. API keys never need to reach the browser:

```php
$secret = Live::voice()
    ->model(Azure::model('gpt-realtime'))
    ->voice('marin')
    ->clientSecret();

$answer = Live::voice()
    ->model(Azure::model('gpt-realtime'))
    ->voice('marin')
    ->webRtc($browserOfferSdp);

// Return $answer->sdp from your HTTP endpoint as application/sdp.
// $answer->callId can be used to attach an optional server controller.
if ($answer->callId !== null) {
    $control = Live::voice()
        ->model(Azure::model('gpt-realtime'))
        ->call($answer->callId)
        ->connect(Transport::auto());
}
```

`clientSecret()` and `webRtc()` are also available on Azure's realtime
transcription and translation builders because those models document the same
WebRTC connection pattern.

### SIP calls and sideband control

Verify the exact raw webhook body before accepting an incoming call:

```php
$event = Azure::verifyWebhook($rawBody, $requestHeaders, $signingSecret);

if ($event['type'] === 'realtime.call.incoming') {
    $call = Live::voice()
        ->model(Azure::model('gpt-realtime'))
        ->instructions('Answer as the support desk.')
        ->call($event['data']['call_id'])
        ->accept();

    // Optional server-side WebSocket attached to the provider-hosted call.
    $control = $call->connect(Transport::auto());
    $control->requestResponse();

    // Later:
    $call->hangup();
}
```

The SIP provider carries media. The sideband WebSocket lets PHP monitor events,
update the session, run tools, and send response commands using the existing
call ID.

## Embeddings

```php
use AiSdk\Azure;
use AiSdk\Generate;

$result = Generate::embedding(['Search query', 'Document text'])
    ->model(Azure::model('my-embedding-deployment'))
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

The default suite uses protocol fixtures and conformance checks. Credentialed
Live network verification is separate from the default test run.

## Links

- [Azure OpenAI Embeddings](https://learn.microsoft.com/en-us/azure/foundry/openai/how-to/embeddings)
- [Azure OpenAI Responses API](https://learn.microsoft.com/en-us/azure/foundry/openai/how-to/responses)
- [Azure OpenAI Transcriptions API](https://learn.microsoft.com/en-us/azure/foundry/openai/reference#transcriptions---create)
- [Azure Realtime WebSocket](https://learn.microsoft.com/en-us/azure/foundry/openai/how-to/realtime-audio-websockets)
- [Azure Realtime WebRTC](https://learn.microsoft.com/en-us/azure/foundry/openai/how-to/realtime-audio-webrtc)
- [Azure Realtime SIP](https://learn.microsoft.com/en-us/azure/foundry/openai/how-to/realtime-audio-sip)
- [GPT Realtime Whisper](https://learn.microsoft.com/en-us/azure/foundry/openai/concepts/gpt-realtime-whisper)
- [GPT Realtime Translate](https://learn.microsoft.com/en-us/azure/foundry/openai/concepts/gpt-realtime-translate)
- [Azure OpenAI webhooks](https://learn.microsoft.com/en-us/azure/foundry/openai/how-to/webhooks)
- [Core Package](https://github.com/phpaisdk/core)
