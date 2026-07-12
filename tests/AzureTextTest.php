<?php

declare(strict_types=1);

use AiSdk\Azure;
use AiSdk\Azure\AzureOptions;
use AiSdk\Azure\Tests\Fakes\FakeHttpClient;
use AiSdk\Exceptions\InvalidArgumentException;
use AiSdk\Generate;
use AiSdk\Reasoning;
use AiSdk\Support\Sdk;
use Nyholm\Psr7\Factory\Psr17Factory;

afterEach(function () {
    Generate::reset();
    Azure::reset();
});

function configureAzureWith(FakeHttpClient $client): void
{
    $factory = new Psr17Factory();
    Generate::configure(new Sdk(
        httpClient: $client,
        requestFactory: $factory,
        streamFactory: $factory,
    ));
}

it('generates text end to end through the Azure vertical', function () {
    $client = new FakeHttpClient(200, json_encode([
        'id' => 'chatcmpl_azure',
        'object' => 'chat.completion',
        'model' => 'gpt-4o',
        'choices' => [['index' => 0, 'message' => ['content' => 'Hello from Azure'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 8, 'completion_tokens' => 4],
    ]));
    configureAzureWith($client);

    Azure::create(['apiKey' => 'azure-test', 'resourceName' => 'my-resource']);

    $result = Generate::text('Hi')->model(Azure::model('gpt-4o'))->run();

    expect($result->text)->toBe('Hello from Azure')
        ->and($result->usage->inputTokens)->toBe(8)
        ->and($result->providerMetadata['azure']['id'])->toBe('chatcmpl_azure');

    $body = $client->sentBody();
    expect($body['model'])->toBe('gpt-4o')
        ->and($body['stream'])->toBeFalse();

    $uri = $client->lastRequest->getUri();
    expect($uri->getPath())->toBe('/openai/v1/chat/completions')
        ->and($uri->getQuery())->toBe('')
        ->and($client->lastRequest->getHeaderLine('api-key'))->toBe('azure-test');
});

it('normalizes explicit Azure endpoint forms', function () {
    $options = AzureOptions::fromArray([
        'apiKey' => 'azure-test',
        'baseUrl' => 'https://my-resource.openai.azure.com/openai/v1/',
    ]);

    expect($options->chatCompletionsUrl('deployment'))->toBe('https://my-resource.openai.azure.com/openai/v1/chat/completions');
});

it('requires an Azure resource endpoint', function () {
    AzureOptions::fromArray(['apiKey' => 'azure-test']);
})->throws(InvalidArgumentException::class);

it('uses deployment based urls when enabled', function () {
    $client = new FakeHttpClient(200, json_encode([
        'choices' => [['message' => ['content' => 'Done'], 'finish_reason' => 'stop']],
    ]));
    configureAzureWith($client);

    Azure::create([
        'apiKey' => 'azure-test',
        'resourceName' => 'my-resource',
        'useDeploymentBasedUrls' => true,
        'apiVersion' => '2024-10-21',
    ]);

    Generate::text('Hi')->model(Azure::model('my-deployment'))->run();

    $uri = $client->lastRequest->getUri();
    expect($uri->getPath())->toBe('/openai/deployments/my-deployment/chat/completions')
        ->and($uri->getQuery())->toContain('api-version=2024-10-21');
});

it('maps portable reasoning effort onto the request', function () {
    $client = new FakeHttpClient(200, json_encode([
        'choices' => [['message' => ['content' => 'Done'], 'finish_reason' => 'stop']],
    ]));
    configureAzureWith($client);
    Azure::create(['apiKey' => 'azure-test', 'resourceName' => 'my-resource']);

    Generate::text('Think briefly.')
        ->model(Azure::model('o3-mini'))
        ->reasoning(Reasoning::effort('low'))
        ->run();

    expect($client->sentBody()['reasoning_effort'])->toBe('low');
});

it('authenticates with a Microsoft Entra ID token', function () {
    $client = new FakeHttpClient(200, json_encode([
        'choices' => [['message' => ['content' => 'Done'], 'finish_reason' => 'stop']],
    ]));
    configureAzureWith($client);

    Azure::create(['entraToken' => 'entra-abc', 'resourceName' => 'my-resource']);

    Generate::text('Hi')->model(Azure::model('gpt-4o'))->run();

    expect($client->lastRequest->getHeaderLine('Authorization'))->toBe('Bearer entra-abc')
        ->and($client->lastRequest->getHeaderLine('api-key'))->toBe('');
});

it('authenticates with an Entra ID token provider', function () {
    $client = new FakeHttpClient(200, json_encode([
        'choices' => [['message' => ['content' => 'Done'], 'finish_reason' => 'stop']],
    ]));
    configureAzureWith($client);

    Azure::create([
        'resourceName' => 'my-resource',
        'tokenProvider' => fn(): string => 'provided-token',
    ]);

    Generate::text('Hi')->model(Azure::model('gpt-4o'))->run();

    expect($client->lastRequest->getHeaderLine('Authorization'))->toBe('Bearer provided-token');
});

it('accepts arbitrary Azure deployment names', function () {
    Azure::create(['apiKey' => 'azure-test', 'resourceName' => 'my-resource']);

    expect(Azure::model('team-chat-deployment')->modelId())->toBe('team-chat-deployment')
        ->and(Azure::model('team-reasoning-deployment')->modelId())->toBe('team-reasoning-deployment');
});

it('generates text through the Azure Responses API', function () {
    $client = new FakeHttpClient(200, json_encode([
        'id' => 'resp_azure',
        'status' => 'completed',
        'model' => 'gpt-4.1',
        'output' => [[
            'type' => 'message',
            'content' => [['type' => 'output_text', 'text' => 'Hello from Responses']],
        ]],
        'usage' => ['input_tokens' => 4, 'output_tokens' => 3, 'total_tokens' => 7],
    ]));
    configureAzureWith($client);
    Azure::create(['apiKey' => 'azure-test', 'resourceName' => 'my-resource', 'api' => 'responses']);

    $result = Generate::text('Hi')->model(Azure::model('gpt-4.1'))->run();

    expect($result->text)->toBe('Hello from Responses')
        ->and($client->lastRequest->getUri()->getPath())->toBe('/openai/v1/responses')
        ->and($client->sentBody())->toHaveKey('input')->not->toHaveKeys(['messages', 'api']);
});

it('allows per-request Azure API selection', function () {
    $client = new FakeHttpClient(200, json_encode([
        'id' => 'resp_azure',
        'status' => 'completed',
        'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Done']]]],
    ]));
    configureAzureWith($client);
    Azure::create(['apiKey' => 'azure-test', 'resourceName' => 'my-resource']);

    Generate::text('Hi')
        ->model(Azure::model('gpt-4.1'))
        ->providerOptions('azure', ['api' => 'responses'])
        ->run();

    expect($client->lastRequest->getUri()->getPath())->toBe('/openai/v1/responses');
});

it('rejects Responses with classic deployment URLs', function () {
    $client = new FakeHttpClient(200, '{}');
    configureAzureWith($client);
    Azure::create([
        'apiKey' => 'azure-test',
        'resourceName' => 'my-resource',
        'api' => 'responses',
        'useDeploymentBasedUrls' => true,
    ]);

    Generate::text('Hi')->model(Azure::model('gpt-4.1'))->run();
})->throws(InvalidArgumentException::class, 'Azure Responses requires the v1 endpoint');
