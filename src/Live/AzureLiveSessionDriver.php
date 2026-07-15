<?php

declare(strict_types=1);

namespace AiSdk\Azure\Live;

use AiSdk\Azure\AzureOptions;
use AiSdk\Exceptions\InvalidArgumentException;
use AiSdk\Exceptions\InvalidResponseException;
use AiSdk\Live\AudioDelta;
use AiSdk\Live\Contracts\LiveSessionDriverInterface;
use AiSdk\Live\Contracts\TransportConnectionInterface;
use AiSdk\Live\Contracts\TransportInterface;
use AiSdk\Live\Interrupted;
use AiSdk\Live\LiveClosed;
use AiSdk\Live\LiveError;
use AiSdk\Live\LiveEvent;
use AiSdk\Live\LiveOperation;
use AiSdk\Live\LiveRequest;
use AiSdk\Live\ProviderEvent;
use AiSdk\Live\ResponseCompleted;
use AiSdk\Live\SpeechStarted;
use AiSdk\Live\SpeechStopped;
use AiSdk\Live\TextDelta;
use AiSdk\Live\ToolCallEvent;
use AiSdk\Live\TranscriptCompleted;
use AiSdk\Live\TranscriptDelta;
use AiSdk\Live\TranscriptSource;
use AiSdk\Live\TransportFrame;
use AiSdk\Live\TransportFrameType;
use AiSdk\Live\UsageEvent;
use AiSdk\Live\WebSocketEndpoint;
use AiSdk\Support\Json;

/** Azure OpenAI GA Realtime WebSocket codec, including call sideband control. */
final class AzureLiveSessionDriver implements LiveSessionDriverInterface
{
    private readonly TransportConnectionInterface $connection;

    /** @var array<string, true> */
    private array $handledToolCalls = [];

    /** @var array<string, true> */
    private array $pendingToolCalls = [];

    /** @var array<string, true> */
    private array $submittedToolResults = [];

    /** @var list<array<string, mixed>> */
    private array $pendingPayloads = [];

    private bool $toolTurnComplete = false;

    public function __construct(
        private readonly string $modelId,
        private readonly AzureOptions $options,
        private readonly LiveRequest $request,
        TransportInterface $transport,
        ?string $callId = null,
    ) {
        $endpoint = new WebSocketEndpoint(
            $callId === null
                ? $options->liveWebSocketUrl($modelId, $request->operation)
                : $options->liveCallWebSocketUrl($callId),
            $options->authHeaders(),
        );

        if (! $transport->supports($endpoint)) {
            throw new InvalidArgumentException(
                'The selected transport does not support Azure OpenAI Realtime WebSocket endpoints.',
                ['provider' => AzureOptions::PROVIDER_NAME],
            );
        }

        $this->connection = $transport->connect($endpoint);
        $configuration = AzureLiveConfiguration::forUpdate($modelId, $request);
        if ($callId !== null) {
            unset($configuration['model']);
        }
        $this->sendJson([
            'type' => 'session.update',
            'session' => $configuration,
        ]);
        $this->awaitSessionUpdated();
    }

    public function sendAudio(string $bytes): void
    {
        $type = $this->request->operation === LiveOperation::Translate
            ? 'session.input_audio_buffer.append'
            : 'input_audio_buffer.append';

        $this->sendJson(['type' => $type, 'audio' => base64_encode($bytes)]);
    }

    public function sendText(string $text): void
    {
        if ($this->request->operation !== LiveOperation::Voice) {
            throw new InvalidArgumentException('Azure realtime transcription and translation sessions accept audio input only.');
        }

        $this->sendJson([
            'type' => 'conversation.item.create',
            'item' => [
                'type' => 'message',
                'role' => 'user',
                'content' => [['type' => 'input_text', 'text' => $text]],
            ],
        ]);
    }

    public function commitAudio(): void
    {
        if ($this->request->operation === LiveOperation::Translate) {
            throw new InvalidArgumentException('Azure realtime translation streams audio continuously and has no input-buffer commit event.');
        }

        $this->sendJson(['type' => 'input_audio_buffer.commit']);
    }

    public function clearAudio(): void
    {
        if ($this->request->operation === LiveOperation::Translate) {
            throw new InvalidArgumentException('Azure realtime translation streams audio continuously and has no input-buffer clear event.');
        }

        $this->sendJson(['type' => 'input_audio_buffer.clear']);
    }

    public function requestResponse(): void
    {
        if ($this->request->operation !== LiveOperation::Voice) {
            throw new InvalidArgumentException('Azure realtime transcription and translation sessions generate output directly from streamed audio.');
        }

        $this->sendJson(['type' => 'response.create']);
    }

    public function cancelResponse(): void
    {
        if ($this->request->operation !== LiveOperation::Voice) {
            throw new InvalidArgumentException('Azure realtime transcription and translation sessions do not expose response cancellation.');
        }

        $this->sendJson(['type' => 'response.cancel']);
    }

    public function sendToolResult(string $callId, mixed $result): void
    {
        if ($this->request->operation !== LiveOperation::Voice) {
            throw new InvalidArgumentException('Azure realtime tools are available only in voice-agent sessions.');
        }

        $output = is_string($result) ? $result : Json::encode($result);
        $this->sendJson([
            'type' => 'conversation.item.create',
            'item' => [
                'type' => 'function_call_output',
                'call_id' => $callId,
                'output' => $output,
            ],
        ]);
        $this->submittedToolResults[$callId] = true;
        $this->continueAfterToolResults();
    }

    public function events(): iterable
    {
        foreach ($this->pendingPayloads as $payload) {
            yield from $this->decode($payload);
        }
        $this->pendingPayloads = [];

        while (! $this->connection->isClosed()) {
            $frame = $this->connection->receive();
            if ($frame === null) {
                yield new LiveClosed();

                break;
            }

            if ($frame->type !== TransportFrameType::Text) {
                yield new ProviderEvent('transport.binary', ['bytes' => base64_encode($frame->payload)]);

                continue;
            }

            yield from $this->decode(Json::decode($frame->payload, 'azure realtime event'));
        }
    }

    public function close(): void
    {
        if (! $this->connection->isClosed()) {
            $this->connection->close();
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return iterable<LiveEvent>
     */
    private function decode(array $payload): iterable
    {
        $type = is_string($payload['type'] ?? null) ? $payload['type'] : 'unknown';

        if (in_array($type, ['response.output_audio.delta', 'response.audio.delta', 'session.output_audio.delta'], true)) {
            $delta = $payload['delta'] ?? null;
            if (is_string($delta)) {
                $bytes = base64_decode($delta, true);
                if ($bytes !== false) {
                    yield new AudioDelta($bytes);

                    return;
                }
            }
        }

        if (in_array($type, ['response.output_text.delta', 'response.text.delta'], true)) {
            $delta = $payload['delta'] ?? $payload['text'] ?? null;
            if (is_string($delta)) {
                if ($this->request->operation === LiveOperation::Transcribe) {
                    $itemId = is_string($payload['item_id'] ?? null) ? $payload['item_id'] : null;
                    yield new TranscriptDelta($delta, $itemId, TranscriptSource::Input);
                } else {
                    yield new TextDelta($delta);
                }

                return;
            }
        }

        if (in_array($type, [
            'response.output_audio_transcript.delta',
            'response.audio_transcript.delta',
            'conversation.item.input_audio_transcription.delta',
            'session.input_audio_transcription.delta',
            'session.input_transcript.delta',
            'session.output_transcript.delta',
        ], true)) {
            $delta = $payload['delta'] ?? $payload['text'] ?? null;
            if (is_string($delta)) {
                $itemId = is_string($payload['item_id'] ?? null) ? $payload['item_id'] : null;
                yield new TranscriptDelta(
                    $delta,
                    $itemId,
                    in_array($type, [
                        'conversation.item.input_audio_transcription.delta',
                        'session.input_audio_transcription.delta',
                        'session.input_transcript.delta',
                    ], true) ? TranscriptSource::Input : TranscriptSource::Output,
                );

                return;
            }
        }

        if (in_array($type, [
            'conversation.item.input_audio_transcription.completed',
            'session.input_audio_transcription.completed',
            'session.input_transcript.done',
        ], true)) {
            $transcript = $payload['transcript'] ?? $payload['text'] ?? null;
            if (is_string($transcript)) {
                $itemId = is_string($payload['item_id'] ?? null) ? $payload['item_id'] : null;
                yield new TranscriptCompleted($transcript, $itemId, TranscriptSource::Input);

                return;
            }
        }

        if (in_array($type, [
            'response.output_audio_transcript.done',
            'response.audio_transcript.done',
            'session.output_transcript.done',
        ], true)) {
            $text = $payload['text'] ?? $payload['transcript'] ?? null;
            if (is_string($text) && $text !== '') {
                $itemId = is_string($payload['item_id'] ?? null) ? $payload['item_id'] : null;
                yield new TranscriptCompleted($text, $itemId, TranscriptSource::Output);

                return;
            }
        }

        if (in_array($type, ['response.output_text.done', 'response.text.done'], true) && $this->request->operation !== LiveOperation::Voice) {
            $text = $payload['text'] ?? $payload['transcript'] ?? null;
            if (is_string($text) && $text !== '') {
                $itemId = is_string($payload['item_id'] ?? null) ? $payload['item_id'] : null;
                yield new TranscriptCompleted(
                    $text,
                    $itemId,
                    $this->request->operation === LiveOperation::Transcribe
                        ? TranscriptSource::Input
                        : TranscriptSource::Output,
                );

                return;
            }
        }

        if (in_array($type, ['input_audio_buffer.speech_started', 'session.input_audio_buffer.speech_started'], true)) {
            $start = is_numeric($payload['audio_start_ms'] ?? null) ? (int) $payload['audio_start_ms'] : null;
            yield new SpeechStarted($start);

            return;
        }

        if (in_array($type, ['input_audio_buffer.speech_stopped', 'session.input_audio_buffer.speech_stopped'], true)) {
            $end = is_numeric($payload['audio_end_ms'] ?? null) ? (int) $payload['audio_end_ms'] : null;
            yield new SpeechStopped($end);

            return;
        }

        if (in_array($type, ['response.cancelled', 'output_audio_buffer.cleared'], true)) {
            $responseId = is_string($payload['response_id'] ?? null) ? $payload['response_id'] : null;
            yield new Interrupted($responseId);

            return;
        }

        if (in_array($type, ['response.done', 'response.completed'], true)) {
            foreach ($this->toolCallsFromResponseDone($payload) as $event) {
                yield $event;
            }

            $response = is_array($payload['response'] ?? null) ? $payload['response'] : [];
            $responseId = is_string($response['id'] ?? null)
                ? $response['id']
                : (is_string($payload['response_id'] ?? null) ? $payload['response_id'] : null);

            if (($response['status'] ?? null) === 'cancelled') {
                yield new Interrupted($responseId);
            }

            yield new ResponseCompleted($responseId, $response);

            $usage = $response['usage'] ?? $payload['usage'] ?? null;
            if (is_array($usage)) {
                $numeric = $this->numericUsage($usage);
                if ($numeric !== []) {
                    yield new UsageEvent($numeric);
                }
            }

            $this->toolTurnComplete = $this->pendingToolCalls !== [];
            $this->continueAfterToolResults();

            return;
        }

        if ($type === 'response.function_call_arguments.done') {
            foreach ($this->handleToolCall(
                $payload['call_id'] ?? null,
                $payload['name'] ?? null,
                $payload['arguments'] ?? null,
            ) as $event) {
                yield $event;
            }

            return;
        }

        if ($type === 'response.output_item.done' && is_array($payload['item'] ?? null)) {
            $item = $payload['item'];
            if (($item['type'] ?? null) === 'function_call') {
                foreach ($this->handleToolCall(
                    $item['call_id'] ?? null,
                    $item['name'] ?? null,
                    $item['arguments'] ?? null,
                ) as $event) {
                    yield $event;
                }

                return;
            }
        }

        if ($type === 'session.closed') {
            if (! $this->connection->isClosed()) {
                $this->connection->close();
            }

            yield new LiveClosed(
                is_int($payload['code'] ?? null) ? $payload['code'] : null,
                is_string($payload['reason'] ?? null) ? $payload['reason'] : null,
            );

            return;
        }

        if (in_array($type, ['error', 'session.error'], true)) {
            $error = is_array($payload['error'] ?? null) ? $payload['error'] : [];
            $message = is_string($error['message'] ?? null)
                ? $error['message']
                : (is_string($payload['message'] ?? null) ? $payload['message'] : 'Azure OpenAI Realtime returned an error.');
            $code = isset($error['code']) ? (string) $error['code'] : null;
            yield new LiveError($message, $code, $error !== [] ? $error : $payload);

            return;
        }

        yield new ProviderEvent($type, $payload);
    }

    /** @return array<string, mixed> */
    private function arguments(mixed $arguments): array
    {
        if (is_array($arguments)) {
            return $arguments;
        }

        if (! is_string($arguments) || $arguments === '') {
            return [];
        }

        $decoded = Json::decodeValue($arguments, 'azure realtime tool arguments');

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<LiveEvent>
     */
    private function toolCallsFromResponseDone(array $payload): array
    {
        $output = $payload['response']['output'] ?? null;
        if (! is_array($output)) {
            return [];
        }

        $events = [];
        foreach ($output as $item) {
            if (! is_array($item) || ($item['type'] ?? null) !== 'function_call') {
                continue;
            }

            array_push($events, ...$this->handleToolCall(
                $item['call_id'] ?? null,
                $item['name'] ?? null,
                $item['arguments'] ?? null,
            ));
        }

        return $events;
    }

    /** @return list<LiveEvent> */
    private function handleToolCall(mixed $callId, mixed $name, mixed $arguments): array
    {
        if (! is_string($callId) || $callId === '') {
            return [];
        }

        $this->pendingToolCalls[$callId] = true;
        if (isset($this->handledToolCalls[$callId])) {
            return [];
        }

        $this->handledToolCalls[$callId] = true;

        return [new ToolCallEvent(
            $callId,
            is_string($name) ? $name : '',
            $this->arguments($arguments),
        )];
    }

    /** Request exactly one continuation after all calls in the response have results. */
    private function continueAfterToolResults(): void
    {
        if (! $this->toolTurnComplete || $this->pendingToolCalls === []) {
            return;
        }

        foreach ($this->pendingToolCalls as $callId => $_) {
            if (! isset($this->submittedToolResults[$callId])) {
                return;
            }
        }

        $this->sendJson(['type' => 'response.create']);
        $this->pendingToolCalls = [];
        $this->submittedToolResults = [];
        $this->toolTurnComplete = false;
    }

    /**
     * @param  array<string, mixed>  $usage
     * @return array<string, int|float>
     */
    private function numericUsage(array $usage, string $prefix = ''): array
    {
        $normalized = [];
        foreach ($usage as $name => $value) {
            $key = $prefix === '' ? (string) $name : $prefix . '.' . $name;
            if (is_int($value) || is_float($value)) {
                $normalized[$key] = $value;
            } elseif (is_array($value)) {
                $normalized = array_replace($normalized, $this->numericUsage($value, $key));
            }
        }

        return $normalized;
    }

    private function awaitSessionUpdated(): void
    {
        while (true) {
            $frame = $this->connection->receive();
            if ($frame === null) {
                throw InvalidResponseException::forProvider(
                    AzureOptions::PROVIDER_NAME,
                    'Azure OpenAI Realtime closed before acknowledging the session update.',
                );
            }

            if ($frame->type !== TransportFrameType::Text) {
                throw InvalidResponseException::forProvider(
                    AzureOptions::PROVIDER_NAME,
                    'Azure OpenAI Realtime returned a binary frame before acknowledging the session update.',
                );
            }

            $payload = Json::decode($frame->payload, 'azure realtime session update response');
            $type = is_string($payload['type'] ?? null) ? $payload['type'] : 'unknown';
            if ($type === 'session.updated') {
                return;
            }

            if (in_array($type, ['error', 'session.error'], true)) {
                $error = is_array($payload['error'] ?? null) ? $payload['error'] : [];

                throw InvalidResponseException::forProvider(
                    AzureOptions::PROVIDER_NAME,
                    is_string($error['message'] ?? null)
                        ? $error['message']
                        : 'Azure OpenAI Realtime rejected the session update.',
                    ['event' => $payload],
                );
            }

            $this->pendingPayloads[] = $payload;
        }
    }

    /** @param array<string, mixed> $payload */
    private function sendJson(array $payload): void
    {
        $this->connection->send(TransportFrame::text(Json::encode($payload)));
    }
}
