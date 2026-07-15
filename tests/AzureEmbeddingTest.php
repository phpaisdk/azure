<?php

declare(strict_types=1);

use AiSdk\Azure;
use AiSdk\Azure\Tests\Fakes\FakeHttpClient;
use AiSdk\Generate;
use AiSdk\Support\Sdk;
use Nyholm\Psr7\Factory\Psr17Factory;

afterEach(function () {
    Azure::reset();
    Generate::reset();
});

it('generates embeddings through an Azure deployment URL', function () {
    $client = new FakeHttpClient(200, json_encode([
        'data' => [['index' => 0, 'embedding' => [0.1, 0.2]]],
        'model' => 'text-embedding-3-small',
        'usage' => ['prompt_tokens' => 3, 'total_tokens' => 3],
    ]));
    $factory = new Psr17Factory();
    Generate::configure(new Sdk($client, $factory, $factory));
    Azure::create([
        'apiKey' => 'azure-test',
        'resourceName' => 'example',
        'apiVersion' => '2024-10-21',
        'useDeploymentBasedUrls' => true,
    ]);

    $result = Generate::embedding('A document')
        ->model(Azure::model('embedding-deployment'))
        ->dimensions(512)
        ->run();

    expect($result->output->vector)->toBe([0.1, 0.2])
        ->and((string) $client->lastRequest?->getUri())->toBe('https://example.openai.azure.com/openai/deployments/embedding-deployment/embeddings?api-version=2024-10-21')
        ->and($client->lastRequest?->getHeaderLine('api-key'))->toBe('azure-test')
        ->and($client->sentBody())->toBe([
            'input' => ['A document'],
            'encoding_format' => 'float',
            'dimensions' => 512,
        ]);
});

it('uses the Azure v1 embedding URL when deployment URLs are disabled', function () {
    $client = new FakeHttpClient(200, json_encode([
        'data' => [['index' => 0, 'embedding' => [0.1]]],
    ]));
    $factory = new Psr17Factory();
    Generate::configure(new Sdk($client, $factory, $factory));
    Azure::create(['apiKey' => 'azure-test', 'baseUrl' => 'https://example.openai.azure.com/openai/v1']);

    Generate::embedding('A document')->model(Azure::model('text-embedding-3-small'))->run();

    expect((string) $client->lastRequest?->getUri())->toBe('https://example.openai.azure.com/openai/v1/embeddings');
});

it('requires API key authentication for Azure v1 embeddings', function () {
    $client = new FakeHttpClient(200, '{}');
    $factory = new Psr17Factory();
    Generate::configure(new Sdk($client, $factory, $factory));
    Azure::create([
        'entraToken' => 'entra-test',
        'baseUrl' => 'https://example.openai.azure.com',
    ]);

    expect(fn() => Generate::embedding('A document')
        ->model(Azure::model('text-embedding-3-small'))
        ->run())
        ->toThrow(\AiSdk\Exceptions\InvalidArgumentException::class, 'v1 embeddings require apiKey')
        ->and($client->lastRequest)->toBeNull();
});

it('uses the API key for Azure v1 embeddings when Entra is also configured', function () {
    $client = new FakeHttpClient(200, json_encode([
        'data' => [['index' => 0, 'embedding' => [0.1]]],
    ]));
    $factory = new Psr17Factory();
    Generate::configure(new Sdk($client, $factory, $factory));
    Azure::create([
        'apiKey' => 'azure-test',
        'entraToken' => 'entra-test',
        'baseUrl' => 'https://example.openai.azure.com',
    ]);

    Generate::embedding('A document')
        ->model(Azure::model('text-embedding-3-small'))
        ->run();

    expect($client->lastRequest?->getHeaderLine('api-key'))->toBe('azure-test')
        ->and($client->lastRequest?->hasHeader('Authorization'))->toBeFalse();
});
