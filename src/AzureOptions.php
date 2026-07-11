<?php

declare(strict_types=1);

namespace AiSdk\Azure;

use AiSdk\Exceptions\InvalidArgumentException;
use AiSdk\Support\Sdk;
use AiSdk\Utils\Support\Env;
use AiSdk\Utils\Support\Url;

final class AzureOptions
{
    public const string PROVIDER_NAME = 'azure';

    public const string DEFAULT_API_VERSION = '2024-10-21';

    /**
     * @param  array<string, string>  $headers
     * @param  (callable(): string)|null  $tokenProvider
     */
    public function __construct(
        public readonly ?string $apiKey,
        public readonly string $apiVersion,
        public readonly bool $useDeploymentBasedUrls,
        public readonly string $endpoint,
        public readonly ?string $entraToken = null,
        public readonly mixed $tokenProvider = null,
        public readonly array $headers = [],
        public readonly ?Sdk $sdk = null,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config = []): self
    {
        $tokenProvider = isset($config['tokenProvider']) && is_callable($config['tokenProvider'])
            ? $config['tokenProvider']
            : null;

        $entraToken = isset($config['entraToken']) ? (string) $config['entraToken'] : null;
        if ($entraToken === null || $entraToken === '') {
            $entraToken = Env::loadOptionalSetting(null, 'AZURE_OPENAI_AD_TOKEN');
        }
        $entraToken = ($entraToken !== null && $entraToken !== '') ? $entraToken : null;

        $explicitApiKey = isset($config['apiKey']) ? (string) $config['apiKey'] : null;
        $apiKey = Env::loadOptionalSetting($explicitApiKey, 'AZURE_OPENAI_API_KEY');
        $apiKey = ($apiKey !== null && $apiKey !== '') ? $apiKey : null;

        if ($apiKey === null && $entraToken === null && $tokenProvider === null) {
            throw new InvalidArgumentException(
                'Azure OpenAI requires one of: apiKey / AZURE_OPENAI_API_KEY, entraToken / AZURE_OPENAI_AD_TOKEN, or a tokenProvider callable (Microsoft Entra ID).',
                ['provider' => self::PROVIDER_NAME],
            );
        }

        $apiVersion = isset($config['apiVersion']) && is_string($config['apiVersion']) && $config['apiVersion'] !== ''
            ? $config['apiVersion']
            : self::DEFAULT_API_VERSION;

        $useDeployment = isset($config['useDeploymentBasedUrls']) && (bool) $config['useDeploymentBasedUrls'];

        $explicitBase = Env::loadOptionalSetting(isset($config['baseUrl']) ? (string) $config['baseUrl'] : null, 'AZURE_OPENAI_BASE_URL');
        $resource = isset($config['resourceName']) ? (string) $config['resourceName'] : '';
        if ($resource === '') {
            $resource = Env::loadOptionalSetting(null, 'AZURE_RESOURCE_NAME') ?? '';
        }

        if ($explicitBase !== null && $explicitBase !== '') {
            $endpoint = self::normalizeEndpoint($explicitBase);
        } elseif ($resource !== '') {
            $endpoint = "https://{$resource}.openai.azure.com";
        } else {
            throw new InvalidArgumentException(
                'Azure OpenAI requires resourceName / AZURE_RESOURCE_NAME or baseUrl / AZURE_OPENAI_BASE_URL.',
                ['provider' => self::PROVIDER_NAME],
            );
        }

        /** @var array<string, string> $headers */
        $headers = isset($config['headers']) && is_array($config['headers']) ? $config['headers'] : [];
        $sdk = $config['sdk'] ?? null;

        return new self(
            apiKey: $apiKey,
            apiVersion: $apiVersion,
            useDeploymentBasedUrls: $useDeployment,
            endpoint: $endpoint,
            entraToken: $entraToken,
            tokenProvider: $tokenProvider,
            headers: $headers,
            sdk: $sdk instanceof Sdk ? $sdk : null,
        );
    }

    /**
     * @return array<string, string>
     */
    public function authHeaders(): array
    {
        if ($this->tokenProvider !== null) {
            return array_merge(['Authorization' => 'Bearer ' . ($this->tokenProvider)()], $this->headers);
        }

        if ($this->entraToken !== null) {
            return array_merge(['Authorization' => 'Bearer ' . $this->entraToken], $this->headers);
        }

        return array_merge(['api-key' => (string) $this->apiKey], $this->headers);
    }

    /**
     * @return array<string, string>
     */
    public function embeddingAuthHeaders(): array
    {
        if ($this->useDeploymentBasedUrls) {
            return $this->authHeaders();
        }

        if ($this->apiKey === null) {
            throw new InvalidArgumentException(
                'Azure OpenAI v1 embeddings require apiKey / AZURE_OPENAI_API_KEY authentication.',
                ['provider' => self::PROVIDER_NAME],
            );
        }

        return array_merge(['api-key' => $this->apiKey], $this->headers);
    }

    public function chatCompletionsUrl(string $deploymentId): string
    {
        return $this->operationUrl($deploymentId, 'chat/completions');
    }

    public function imageGenerationsUrl(string $deploymentId): string
    {
        return $this->operationUrl($deploymentId, 'images/generations');
    }

    public function speechUrl(string $deploymentId): string
    {
        return $this->operationUrl($deploymentId, 'audio/speech');
    }

    public function embeddingsUrl(string $deploymentId): string
    {
        return $this->operationUrl($deploymentId, 'embeddings');
    }

    private function operationUrl(string $deploymentId, string $path): string
    {
        $path = ltrim($path, '/');

        if ($this->useDeploymentBasedUrls) {
            $url = $this->endpoint . '/openai/deployments/' . rawurlencode($deploymentId) . '/' . $path;

            return $url . '?api-version=' . rawurlencode($this->apiVersion);
        }

        return $this->endpoint . '/openai/v1/' . $path;
    }

    private static function normalizeEndpoint(string $endpoint): string
    {
        $endpoint = Url::withoutTrailingSlash($endpoint);

        return (string) preg_replace('#/openai(?:/v1)?$#', '', $endpoint);
    }
}
