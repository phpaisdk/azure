<?php

declare(strict_types=1);

use AiSdk\Azure;
use AiSdk\Azure\Tests\Fakes\FakeLiveHttpClient;
use AiSdk\Azure\Tests\Fakes\FakeLiveTransport;
use AiSdk\Contracts\LiveProviderInterface;
use AiSdk\Exceptions\InvalidArgumentException;
use AiSdk\Exceptions\InvalidResponseException;
use AiSdk\Generate;
use AiSdk\Live;
use AiSdk\Live\AudioDelta;
use AiSdk\Live\Interrupted;
use AiSdk\Live\LiveClosed;
use AiSdk\Live\ProviderEvent;
use AiSdk\Live\ResponseCompleted;
use AiSdk\Live\SpeechStarted;
use AiSdk\Live\TextDelta;
use AiSdk\Live\ToolCallEvent;
use AiSdk\Live\TranscriptCompleted;
use AiSdk\Live\TranscriptDelta;
use AiSdk\Live\TranscriptSource;
use AiSdk\Live\TransportFrame;
use AiSdk\Live\UsageEvent;
use AiSdk\Live\WebSocketEndpoint;
use AiSdk\Schema;
use AiSdk\Support\Sdk;
use AiSdk\Tool;
use Nyholm\Psr7\Factory\Psr17Factory;
use StandardWebhooks\Webhook;

afterEach(function () {
    Generate::reset();
    Azure::reset();
});

it('runs Azure OpenAI voice agents over the core transport contract', function () {
    $provider = Azure::create(['apiKey' => 'azure-test', 'resourceName' => 'my-resource']);
    expect($provider)->toBeInstanceOf(LiveProviderInterface::class);
    $transport = new FakeLiveTransport([
        TransportFrame::text(json_encode(['type' => 'response.output_audio.delta', 'delta' => base64_encode('voice-bytes')])),
        TransportFrame::text(json_encode([
            'type' => 'conversation.item.input_audio_transcription.completed',
            'item_id' => 'item-1',
            'transcript' => 'hello',
        ])),
        TransportFrame::text(json_encode(['type' => 'input_audio_buffer.speech_started', 'audio_start_ms' => 120])),
        TransportFrame::text(json_encode([
            'type' => 'response.function_call_arguments.done',
            'call_id' => 'call-1',
            'name' => 'weather',
            'arguments' => '{"city":"Lahore"}',
        ])),
        TransportFrame::text(json_encode([
            'type' => 'response.done',
            'response' => ['id' => 'response-1', 'usage' => ['input_tokens' => 8, 'output_tokens' => 4]],
        ])),
        TransportFrame::text(json_encode([
            'type' => 'response.done',
            'response' => ['id' => 'response-2', 'status' => 'cancelled'],
        ])),
        TransportFrame::text(json_encode(['type' => 'rate_limits.updated', 'rate_limits' => []])),
    ]);
    $weather = Tool::make('weather')->for('Get the weather');

    $session = Live::voice()
        ->model(Azure::model('gpt-realtime'))
        ->instructions('Be concise.')
        ->voice('marin')
        ->inputAudioFormat('g711_ulaw')
        ->outputAudioFormat('pcm16')
        ->tools([$weather])
        ->connect($transport);

    expect($transport->endpoint)->toBeInstanceOf(WebSocketEndpoint::class)
        ->and($transport->endpoint?->url)->toBe('wss://my-resource.openai.azure.com/openai/v1/realtime?model=gpt-realtime')
        ->and($transport->endpoint?->headers['api-key'])->toBe('azure-test');

    $configuration = $transport->connection->sentJson(0)['session'];
    expect($configuration['type'])->toBe('realtime')
        ->and($configuration['model'])->toBe('gpt-realtime')
        ->and($configuration['instructions'])->toBe('Be concise.')
        ->and($configuration['audio']['input']['format'])->toBe(['type' => 'audio/pcmu'])
        ->and($configuration['audio']['output']['voice'])->toBe('marin')
        ->and($configuration['tools'][0]['name'])->toBe('weather');

    $session->sendAudio('microphone-bytes');
    $session->sendText('Hello');
    $session->commitAudio();
    $session->clearAudio();
    $session->requestResponse();
    $session->cancelResponse();

    expect($transport->connection->sentJson(1))->toBe([
        'type' => 'input_audio_buffer.append',
        'audio' => base64_encode('microphone-bytes'),
    ])->and($transport->connection->sentJson(2)['item']['content'][0]['text'])->toBe('Hello')
        ->and($transport->connection->sentJson(3)['type'])->toBe('input_audio_buffer.commit')
        ->and($transport->connection->sentJson(4)['type'])->toBe('input_audio_buffer.clear')
        ->and($transport->connection->sentJson(5)['type'])->toBe('response.create')
        ->and($transport->connection->sentJson(6)['type'])->toBe('response.cancel');

    $events = iterator_to_array($session->events());
    $classes = array_map(static fn(object $event): string => $event::class, $events);
    expect($classes)->toContain(AudioDelta::class, TranscriptCompleted::class, SpeechStarted::class, ToolCallEvent::class, ResponseCompleted::class, Interrupted::class, UsageEvent::class, ProviderEvent::class, LiveClosed::class);
    $transcript = array_values(array_filter($events, static fn(object $event): bool => $event instanceof TranscriptCompleted))[0];
    expect($transcript->source)->toBe(TranscriptSource::Input);

    $session->sendToolResult('call-1', ['temperature' => 31]);
    expect($transport->connection->sentJson(7)['item'])->toMatchArray([
        'type' => 'function_call_output',
        'call_id' => 'call-1',
        'output' => '{"temperature":31}',
    ])->and($transport->connection->sentJson(8)['type'])->toBe('response.create');
});

it('uses Azure dedicated realtime transcription sessions', function () {
    Azure::create(['apiKey' => 'azure-test', 'resourceName' => 'my-resource']);
    $transport = new FakeLiveTransport([
        TransportFrame::text(json_encode([
            'type' => 'conversation.item.input_audio_transcription.delta',
            'delta' => 'partial',
        ])),
        TransportFrame::text(json_encode([
            'type' => 'conversation.item.input_audio_transcription.completed',
            'transcript' => 'complete',
        ])),
    ]);

    $session = Live::transcribe()
        ->model(Azure::model('gpt-realtime-whisper'))
        ->language('en')
        ->audioFormat('g711_alaw')
        ->connect($transport);

    expect($transport->endpoint?->url)->toBe('wss://my-resource.openai.azure.com/openai/v1/realtime?intent=transcription');
    $configuration = $transport->connection->sentJson(0)['session'];
    expect($configuration['type'])->toBe('transcription')
        ->and($configuration['audio']['input']['format'])->toBe(['type' => 'audio/pcma'])
        ->and($configuration['audio']['input']['transcription'])->toBe([
            'model' => 'gpt-realtime-whisper',
            'language' => 'en',
        ]);

    $session->sendAudio('audio');
    $session->commitAudio();
    $events = iterator_to_array($session->events());
    $classes = array_map(static fn(object $event): string => $event::class, $events);
    expect($classes)->toContain(TranscriptDelta::class, TranscriptCompleted::class, LiveClosed::class);
    $transcriptEvents = array_values(array_filter($events, static fn(object $event): bool => $event instanceof TranscriptDelta || $event instanceof TranscriptCompleted));
    expect($transcriptEvents[0]->source)->toBe(TranscriptSource::Input)
        ->and($transcriptEvents[1]->source)->toBe(TranscriptSource::Input);

    expect(fn() => $session->requestResponse())
        ->toThrow(InvalidArgumentException::class, 'generate output directly');
});

it('normalizes Azure transcription model text events as input transcripts', function () {
    Azure::create(['apiKey' => 'azure-test', 'resourceName' => 'my-resource']);
    $transport = new FakeLiveTransport([
        TransportFrame::text(json_encode([
            'type' => 'response.text.delta',
            'delta' => 'partial',
            'item_id' => 'item-1',
        ])),
        TransportFrame::text(json_encode([
            'type' => 'response.text.done',
            'text' => 'complete',
            'item_id' => 'item-1',
        ])),
    ]);

    $session = Live::transcribe()
        ->model(Azure::model('gpt-realtime-whisper'))
        ->connect($transport);

    $events = iterator_to_array($session->events());

    expect($events[0])->toBeInstanceOf(TranscriptDelta::class)
        ->and($events[0]->delta)->toBe('partial')
        ->and($events[0]->itemId)->toBe('item-1')
        ->and($events[0]->source)->toBe(TranscriptSource::Input)
        ->and($events[1])->toBeInstanceOf(TranscriptCompleted::class)
        ->and($events[1]->text)->toBe('complete')
        ->and($events[1]->itemId)->toBe('item-1')
        ->and($events[1]->source)->toBe(TranscriptSource::Input);
});

it('uses Azure dedicated realtime translation sessions', function () {
    Azure::create(['apiKey' => 'azure-test', 'resourceName' => 'my-resource']);
    $transport = new FakeLiveTransport([
        TransportFrame::text(json_encode(['type' => 'response.text.delta', 'text' => 'Hola'])),
        TransportFrame::text(json_encode(['type' => 'response.text.done', 'text' => 'Hola mundo'])),
    ]);

    $session = Live::translate()
        ->model(Azure::model('gpt-realtime-translate'))
        ->from('en')
        ->to('es')
        ->connect($transport);

    expect($transport->endpoint?->url)->toBe('wss://my-resource.openai.azure.com/openai/v1/realtime/translations?model=gpt-realtime-translate')
        ->and($transport->connection->sentJson(0)['session'])->toBe([
            'audio' => ['output' => ['language' => 'es']],
        ]);

    $session->sendAudio('audio');
    expect($transport->connection->sentJson(1)['type'])->toBe('session.input_audio_buffer.append');

    $events = iterator_to_array($session->events());
    expect($events[0])->toBeInstanceOf(TextDelta::class)
        ->and($events[0]->delta)->toBe('Hola')
        ->and($events[1])->toBeInstanceOf(TranscriptCompleted::class)
        ->and($events[1]->source)->toBe(TranscriptSource::Output);

    expect(fn() => $session->commitAudio())
        ->toThrow(InvalidArgumentException::class, 'no input-buffer commit event');

    $session->close();
    expect($transport->connection->isClosed())->toBeTrue();
});

it('creates Azure Realtime client secrets for browser sessions', function () {
    $client = new FakeLiveHttpClient([[
        'status' => 200,
        'body' => json_encode([
            'value' => 'ek_test',
            'expires_at' => 1_900_000_000,
            'session' => ['id' => 'session-1'],
        ]),
    ]]);
    $factory = new Psr17Factory();
    Generate::configure(new Sdk($client, $factory, $factory));
    Azure::create(['apiKey' => 'azure-test', 'resourceName' => 'my-resource']);

    $secret = Live::voice()
        ->model(Azure::model('gpt-realtime'))
        ->voice('marin')
        ->clientSecret();

    expect($secret->value)->toBe('ek_test')
        ->and($secret->expiresAt)->toBe(1_900_000_000)
        ->and($secret->sessionId)->toBe('session-1')
        ->and($client->requests[0]->getUri()->getPath())->toBe('/openai/v1/realtime/client_secrets')
        ->and($client->requests[0]->getHeaderLine('api-key'))->toBe('azure-test')
        ->and($client->sentJson(0)['session']['model'])->toBe('gpt-realtime');
});

it('creates operation-specific Azure client secrets for transcription and translation', function () {
    $client = new FakeLiveHttpClient([
        ['status' => 200, 'body' => json_encode(['value' => 'ek_transcribe'])],
        ['status' => 200, 'body' => json_encode(['value' => 'ek_translate'])],
    ]);
    $factory = new Psr17Factory();
    Generate::configure(new Sdk($client, $factory, $factory));
    Azure::create(['apiKey' => 'azure-test', 'resourceName' => 'my-resource']);

    $transcriptionSecret = Live::transcribe()
        ->model(Azure::model('gpt-realtime-whisper'))
        ->language('en')
        ->clientSecret();
    $translationSecret = Live::translate()
        ->model(Azure::model('gpt-realtime-translate'))
        ->to('es')
        ->clientSecret();

    expect($transcriptionSecret->value)->toBe('ek_transcribe')
        ->and($client->sentJson(0)['session']['type'])->toBe('transcription')
        ->and($client->sentJson(0)['session']['audio']['input']['transcription'])->toBe([
            'model' => 'gpt-realtime-whisper',
            'language' => 'en',
        ])
        ->and($translationSecret->value)->toBe('ek_translate')
        ->and($client->sentJson(1)['session']['type'])->toBe('realtime')
        ->and($client->sentJson(1)['session']['model'])->toBe('gpt-realtime-translate')
        ->and($client->sentJson(1)['session']['audio']['output']['language'])->toBe('es');
});

it('exchanges WebRTC SDP through an ephemeral Azure credential', function () {
    $client = new FakeLiveHttpClient([
        [
            'status' => 200,
            'body' => json_encode(['value' => 'ek_test', 'expires_at' => 1_900_000_000]),
        ],
        [
            'status' => 201,
            'body' => "v=0\r\nanswer",
            'headers' => ['Content-Type' => 'application/sdp', 'Location' => '/openai/v1/realtime/calls/call_123'],
        ],
    ]);
    $factory = new Psr17Factory();
    Generate::configure(new Sdk($client, $factory, $factory));
    Azure::create(['apiKey' => 'azure-test', 'resourceName' => 'my-resource']);

    $answer = Live::voice()
        ->model(Azure::model('gpt-realtime'))
        ->webRtc("v=0\r\noffer");

    expect($answer->sdp)->toBe("v=0\r\nanswer")
        ->and($answer->callId)->toBe('call_123')
        ->and($client->requests[1]->getUri()->getPath())->toBe('/openai/v1/realtime/calls')
        ->and($client->requests[1]->getHeaderLine('Authorization'))->toBe('Bearer ek_test')
        ->and($client->requests[1]->getHeaderLine('Content-Type'))->toBe('application/sdp')
        ->and((string) $client->requests[1]->getBody())->toBe("v=0\r\noffer");
});

it('accepts and controls Azure provider-hosted calls over sideband WebSocket', function () {
    $client = new FakeLiveHttpClient([
        ['status' => 200, 'body' => '{}'],
        ['status' => 200, 'body' => '{}'],
    ]);
    $factory = new Psr17Factory();
    Generate::configure(new Sdk($client, $factory, $factory));
    Azure::create(['apiKey' => 'azure-test', 'resourceName' => 'my-resource']);
    $transport = new FakeLiveTransport();

    $call = Live::voice()
        ->model(Azure::model('gpt-realtime'))
        ->instructions('Handle the call.')
        ->call('call_123')
        ->accept();
    $control = $call->connect($transport);
    $call->hangup();

    expect($call->id())->toBe('call_123')
        ->and($client->requests[0]->getUri()->getPath())->toBe('/openai/v1/realtime/calls/call_123/accept')
        ->and($client->sentJson(0)['instructions'])->toBe('Handle the call.')
        ->and($transport->endpoint?->url)->toBe('wss://my-resource.openai.azure.com/openai/v1/realtime?call_id=call_123')
        ->and($transport->connection->sentJson(0)['session'])->not->toHaveKey('model')
        ->and($client->requests[1]->getUri()->getPath())->toBe('/openai/v1/realtime/calls/call_123/hangup');

    $control->close();
});

it('continues once after every parallel Azure tool result is submitted', function () {
    Azure::create(['apiKey' => 'azure-test', 'resourceName' => 'my-resource']);
    $transport = new FakeLiveTransport([
        TransportFrame::text(json_encode([
            'type' => 'response.function_call_arguments.done',
            'call_id' => 'call-weather',
            'name' => 'weather',
            'arguments' => '{"city":"Lahore"}',
        ])),
        TransportFrame::text(json_encode([
            'type' => 'response.function_call_arguments.done',
            'call_id' => 'call-time',
            'name' => 'time',
            'arguments' => '{"city":"Lahore"}',
        ])),
        TransportFrame::text(json_encode([
            'type' => 'response.done',
            'response' => [
                'id' => 'response-tools',
                'output' => [
                    ['type' => 'function_call', 'call_id' => 'call-weather', 'name' => 'weather', 'arguments' => '{"city":"Lahore"}'],
                    ['type' => 'function_call', 'call_id' => 'call-time', 'name' => 'time', 'arguments' => '{"city":"Lahore"}'],
                ],
            ],
        ])),
    ]);

    $session = Live::voice()
        ->model(Azure::model('gpt-realtime'))
        ->tools([
            Tool::make('weather')->input(Schema::string('city')->required())->run(fn(string $city): string => "Sunny in {$city}"),
            Tool::make('time')->input(Schema::string('city')->required())->run(fn(string $city): string => "12:00 in {$city}"),
        ])
        ->connect($transport);

    iterator_to_array($session->events());

    $sent = array_map(
        static fn(int $index): array => $transport->connection->sentJson($index),
        array_keys($transport->connection->sent),
    );
    $continuations = array_values(array_filter(
        $sent,
        static fn(array $event): bool => ($event['type'] ?? null) === 'response.create',
    ));

    expect($transport->connection->sent)->toHaveCount(4)
        ->and($transport->connection->sentJson(1)['item']['call_id'])->toBe('call-weather')
        ->and($transport->connection->sentJson(2)['item']['call_id'])->toBe('call-time')
        ->and($continuations)->toHaveCount(1);
});

it('waits for Azure to acknowledge the session update', function () {
    Azure::create(['apiKey' => 'azure-test', 'resourceName' => 'my-resource']);

    expect(fn() => Live::voice()
        ->model(Azure::model('gpt-realtime'))
        ->connect(new FakeLiveTransport([], false)))
        ->toThrow(InvalidResponseException::class, 'closed before acknowledging');
});

it('verifies Azure incoming call webhooks before exposing their call id', function () {
    $payload = json_encode([
        'type' => 'realtime.call.incoming',
        'data' => ['call_id' => 'call_123'],
    ]);
    $secret = 'whsec_' . base64_encode('azure-webhook-secret');
    $timestamp = time();
    $signature = (new Webhook($secret))->sign('wh_123', $timestamp, $payload);

    $event = Azure::verifyWebhook($payload, [
        'Webhook-Id' => 'wh_123',
        'Webhook-Timestamp' => (string) $timestamp,
        'Webhook-Signature' => $signature,
    ], $secret);

    expect($event['type'])->toBe('realtime.call.incoming')
        ->and($event['data']['call_id'])->toBe('call_123');

    expect(fn() => Azure::verifyWebhook($payload, [
        'webhook-id' => 'wh_123',
        'webhook-timestamp' => (string) $timestamp,
        'webhook-signature' => 'v1,invalid',
    ], $secret))->toThrow(InvalidArgumentException::class, 'Invalid Azure OpenAI webhook signature');
});
