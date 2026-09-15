<?php

declare(strict_types=1);

namespace WordPress\MicrosoftCopilotAiProvider\Provider;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\MicrosoftCopilotAiProvider\Availability\CopilotProviderAvailability;
use WordPress\MicrosoftCopilotAiProvider\Metadata\CopilotModelMetadataDirectory;
use WordPress\MicrosoftCopilotAiProvider\Models\CopilotTextGenerationModel;

/**
 * Class for the Microsoft 365 Copilot provider.
 *
 * @since 0.1.0
 */
class CopilotProvider extends AbstractApiProvider
{
    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    protected static function baseUrl(): string
    {
        /*
         * The Copilot Chat API is only published under Graph's /beta ring. Microsoft marks beta
         * endpoints as unsupported for production use and subject to change without notice.
         */
        return 'https://graph.microsoft.com/beta';
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    protected static function createModel(
        ModelMetadata $modelMetadata,
        ProviderMetadata $providerMetadata
    ): ModelInterface {
        foreach ($modelMetadata->getSupportedCapabilities() as $capability) {
            if ($capability->isTextGeneration()) {
                return new CopilotTextGenerationModel($modelMetadata, $providerMetadata);
            }
        }

        throw new RuntimeException(
            'Unsupported model capabilities: ' . implode(', ', $modelMetadata->getSupportedCapabilities())
        );
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    protected static function createProviderMetadata(): ProviderMetadata
    {
        $args = [
            'microsoft-copilot',
            'Microsoft 365 Copilot',
            ProviderTypeEnum::cloud(),
            'https://entra.microsoft.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade',
            /*
             * The SDK only models API key authentication today, but this provider signs requests
             * with a delegated OAuth token obtained through its own settings screen. The declared
             * method is therefore nominal; no API key field is ever consulted.
             */
            RequestAuthenticationMethod::apiKey(),
        ];

        if (version_compare(AiClient::VERSION, '1.2.0', '>=')) {
            $args[] = function_exists('__')
                ? __(
                    'Grounded answers from Microsoft 365 Copilot, using each user\'s own work account.',
                    'ai-provider-for-microsoft-copilot'
                )
                : 'Grounded answers from Microsoft 365 Copilot, using each user\'s own work account.';
        }

        if (version_compare(AiClient::VERSION, '1.3.0', '>=')) {
            $args[] = dirname(__DIR__, 2) . '/assets/images/microsoft-copilot.svg';
        }

        return new ProviderMetadata(...$args);
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    protected static function createProviderAvailability(): ProviderAvailabilityInterface
    {
        /*
         * Availability cannot be probed with a list-models call the way it is for other providers,
         * because Copilot has no such endpoint. It is derived from configuration state instead.
         */
        return new CopilotProviderAvailability();
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface
    {
        return new CopilotModelMetadataDirectory();
    }
}
