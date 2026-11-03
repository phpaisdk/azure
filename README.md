# PHP AI SDK — Azure

Text generation, streaming, tools, images, audio, embeddings, and live sessions with Azure OpenAI for the [PHP AI SDK](https://phpaisdk.com).

## Installation

```bash
composer require aisdk/azure
```

Set `AZURE_OPENAI_API_KEY` and `AZURE_RESOURCE_NAME` in your environment.

## Usage

```php
use AiSdk\Azure;
use AiSdk\Generate;

$result = Generate::text('Explain closures in PHP in one sentence.')
    ->model(Azure::model('your-deployment-name'))
    ->run();

echo $result->text;
```

Use the name of your Azure model deployment, rather than an OpenAI model ID. Choose a current model available in your Azure region.

## Documentation

[Read the documentation](https://phpaisdk.com/docs/0.x/providers/azure) for configuration, supported features, and more examples.

[Contributing](https://github.com/phpaisdk/.github/blob/main/CONTRIBUTING.md) · [Support](https://github.com/phpaisdk/.github/blob/main/SUPPORT.md)

## License

The MIT License (MIT). See [LICENSE](LICENSE) for details.
