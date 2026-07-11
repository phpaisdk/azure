<?php

declare(strict_types=1);

use AiSdk\Azure;
use AiSdk\Azure\Tests\Fakes\FakeHttpClient;
use AiSdk\Generate;
use AiSdk\Support\Sdk;
use Nyholm\Psr7\Factory\Psr17Factory;

afterEach(function () {
    Generate::reset();
    Azure::reset();
});

function configureAzureMediaWith(FakeHttpClient $client): void
{
    $factory = new Psr17Factory();
    Generate::configure(new Sdk(httpClient: $client, requestFactory: $factory, streamFactory: $factory));
    Azure::create(['apiKey' => 'azure-test', 'resourceName' => 'my-resource']);
}

it('generates images through the Azure v1 endpoint', function () {
    $client = new FakeHttpClient(200, json_encode([
        'data' => [['b64_json' => base64_encode('image-bytes')]],
    ]));
    configureAzureMediaWith($client);

    $result = Generate::image('A red cube')
        ->model(Azure::image('gpt-image-1'))
        ->size('1024x1024')
        ->providerOptions('azure', ['background' => 'transparent'])
        ->run();

    expect($result->output->bytes())->toBe('image-bytes')
        ->and((string) $client->lastRequest?->getUri())->toBe('https://my-resource.openai.azure.com/openai/v1/images/generations')
        ->and($client->sentBody()['background'])->toBe('transparent')
        ->and($client->sentBody())->not->toHaveKey('response_format');
});

it('generates speech through the Azure v1 endpoint', function () {
    $client = new FakeHttpClient(200, 'audio-bytes', 'audio/mpeg');
    configureAzureMediaWith($client);

    $result = Generate::speech('Hello')
        ->model(Azure::speech('gpt-4o-mini-tts'))
        ->voice('alloy')
        ->run();

    expect($result->output->data)->toBe('audio-bytes')
        ->and((string) $client->lastRequest?->getUri())->toBe('https://my-resource.openai.azure.com/openai/v1/audio/speech');
});

it('accepts arbitrary Azure deployment names for every selected modality', function () {
    Azure::create(['apiKey' => 'azure-test', 'resourceName' => 'my-resource']);

    expect(Azure::model('team-chat-deployment')->modelId())->toBe('team-chat-deployment')
        ->and(Azure::image('team-image-deployment')->modelId())->toBe('team-image-deployment')
        ->and(Azure::speech('team-speech-deployment')->modelId())->toBe('team-speech-deployment');
});
