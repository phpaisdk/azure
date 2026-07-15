<?php

declare(strict_types=1);

use AiSdk\Azure;
use AiSdk\Azure\Tests\Fakes\FakeHttpClient;
use AiSdk\Content;
use AiSdk\Generate;
use AiSdk\Support\Sdk;
use Nyholm\Psr7\Factory\Psr17Factory;

afterEach(function () {
    Generate::reset();
    Azure::reset();
});

it('uses the Azure deployment transcription endpoint', function () {
    $client = new FakeHttpClient(200, '{"text":"Azure transcript."}');
    $factory = new Psr17Factory();
    Generate::configure(new Sdk($client, $factory, $factory));
    Azure::create([
        'apiKey' => 'azure-test',
        'resourceName' => 'demo',
        'useDeploymentBasedUrls' => true,
        'apiVersion' => '2024-10-21',
    ]);

    $result = Generate::transcription(Content::audio('wav', 'audio/wav', 'clip.wav'))
        ->model(Azure::model('whisper-deployment'))
        ->run();

    expect($result->output->text)->toBe('Azure transcript.')
        ->and((string) $client->lastRequest?->getUri())
        ->toBe('https://demo.openai.azure.com/openai/deployments/whisper-deployment/audio/transcriptions?api-version=2024-10-21')
        ->and($client->lastRequest?->getHeaderLine('api-key'))->toBe('azure-test');
});
