<?php

declare(strict_types=1);

namespace AiSdk\Azure\Live;

use AiSdk\Azure\AzureOptions;
use AiSdk\Exceptions\InvalidArgumentException;
use AiSdk\Live\LiveOperation;
use AiSdk\Live\LiveRequest;
use AiSdk\Tool;

/** Maps provider-neutral Live options to the Azure OpenAI GA session schema. */
final class AzureLiveConfiguration
{
    /** @return array<string, mixed> */
    public static function forUpdate(string $modelId, LiveRequest $request): array
    {
        $configuration = match ($request->operation) {
            LiveOperation::Voice => self::voice($modelId, $request),
            LiveOperation::Transcribe => self::transcribe($modelId, $request),
            LiveOperation::Translate => self::translate($request, false),
        };

        return self::withProviderOptions($configuration, $request);
    }

    /** @return array<string, mixed> */
    public static function forCreation(string $modelId, LiveRequest $request): array
    {
        $configuration = match ($request->operation) {
            LiveOperation::Voice => self::voice($modelId, $request),
            LiveOperation::Transcribe => self::transcribe($modelId, $request),
            LiveOperation::Translate => self::translate($request, true, $modelId),
        };

        return self::withProviderOptions($configuration, $request);
    }

    /** @return array<string, mixed> */
    private static function voice(string $modelId, LiveRequest $request): array
    {
        /** @var array<string, mixed> $configuration */
        $configuration = [
            'type' => 'realtime',
            'model' => $modelId,
            'output_modalities' => ['audio'],
            'audio' => [
                'input' => [
                    'format' => self::audioFormat($request->options['input_audio_format'] ?? null),
                ],
                'output' => [
                    'format' => self::audioFormat($request->options['output_audio_format'] ?? null),
                ],
            ],
        ];

        $instructions = $request->options['instructions'] ?? null;
        if (is_string($instructions) && $instructions !== '') {
            $configuration['instructions'] = $instructions;
        }

        $voice = $request->options['voice'] ?? null;
        if (is_string($voice) && $voice !== '') {
            $configuration['audio']['output']['voice'] = $voice;
        }

        $language = $request->options['language'] ?? null;
        if (is_string($language) && $language !== '') {
            $configuration['audio']['input']['transcription'] = ['language' => $language];
        }

        if (array_key_exists('turn_detection', $request->options)) {
            $configuration['audio']['input']['turn_detection'] = self::turnDetection(
                $request->options['turn_detection'],
            );
        }

        if ($request->tools !== []) {
            $configuration['tools'] = array_map(
                static fn(Tool $tool): array => [
                    'type' => 'function',
                    'name' => $tool->name(),
                    'description' => $tool->description(),
                    'parameters' => $tool->inputSchemaForProvider(),
                ],
                $request->tools,
            );
            $configuration['tool_choice'] = 'auto';
        }

        return $configuration;
    }

    /** @return array<string, mixed> */
    private static function transcribe(string $modelId, LiveRequest $request): array
    {
        /** @var array<string, mixed> $transcription */
        $transcription = ['model' => $modelId];
        $language = $request->options['language'] ?? null;
        if (is_string($language) && $language !== '') {
            $transcription['language'] = $language;
        }

        return [
            'type' => 'transcription',
            'audio' => [
                'input' => [
                    'format' => self::audioFormat($request->options['input_audio_format'] ?? null),
                    'turn_detection' => null,
                    'transcription' => $transcription,
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function translate(LiveRequest $request, bool $forCreation, ?string $modelId = null): array
    {
        $target = $request->options['to'] ?? null;
        if (! is_string($target) || $target === '') {
            throw new InvalidArgumentException('Live::translate() requires to().');
        }

        /** @var array<string, mixed> $configuration */
        $configuration = [
            'audio' => [
                'output' => [
                    'language' => $target,
                ],
            ],
        ];

        if ($forCreation) {
            $configuration = array_replace_recursive([
                'type' => 'realtime',
                'model' => $modelId,
                'audio' => [
                    'input' => [
                        'format' => self::audioFormat($request->options['input_audio_format'] ?? null),
                    ],
                    'output' => [
                        'format' => self::audioFormat($request->options['output_audio_format'] ?? null),
                    ],
                ],
            ], $configuration);
        }

        return $configuration;
    }

    /** @return array<string, int|string> */
    private static function audioFormat(mixed $format): array
    {
        $normalized = is_string($format) && $format !== '' ? strtolower($format) : 'pcm16';

        return match ($normalized) {
            'pcm', 'pcm16', 'audio/pcm' => ['type' => 'audio/pcm', 'rate' => 24_000],
            'g711_ulaw', 'pcmu', 'audio/pcmu' => ['type' => 'audio/pcmu'],
            'g711_alaw', 'pcma', 'audio/pcma' => ['type' => 'audio/pcma'],
            default => ['type' => str_contains($normalized, '/') ? $normalized : 'audio/' . $normalized],
        };
    }

    /** @return array<string, mixed>|null */
    private static function turnDetection(mixed $turnDetection): ?array
    {
        if ($turnDetection === null || $turnDetection === 'none' || $turnDetection === 'disabled') {
            return null;
        }

        if (is_string($turnDetection)) {
            return ['type' => $turnDetection];
        }

        return is_array($turnDetection) ? $turnDetection : null;
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    private static function withProviderOptions(array $configuration, LiveRequest $request): array
    {
        $providerOptions = $request->providerOptions[AzureOptions::PROVIDER_NAME] ?? [];
        $raw = $providerOptions['raw'] ?? null;

        return is_array($raw) ? array_replace_recursive($configuration, $raw) : $configuration;
    }
}
