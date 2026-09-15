<?php

declare(strict_types=1);

namespace WordPress\MicrosoftCopilotAiProvider\Metadata;

use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModelMetadataDirectory;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\MicrosoftCopilotAiProvider\Authentication\CopilotBearerRequestAuthentication;

/**
 * Model metadata directory for Microsoft 365 Copilot.
 *
 * Unlike the other providers, Copilot exposes no model catalogue: the Chat API is a single
 * orchestrated endpoint and Microsoft does not disclose or let callers choose the underlying
 * model. The directory therefore returns one fixed pseudo-model rather than issuing a
 * list-models request.
 *
 * @since 0.1.0
 */
class CopilotModelMetadataDirectory extends AbstractApiBasedModelMetadataDirectory
{
    /**
     * The single model ID exposed by this provider.
     *
     * @since 0.1.0
     *
     * @var string
     */
    public const MODEL_ID = 'microsoft-365-copilot';

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    public function getRequestAuthentication(): RequestAuthenticationInterface
    {
        return new CopilotBearerRequestAuthentication();
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     *
     * @return array<string, ModelMetadata> Map of model ID to model metadata.
     */
    protected function sendListModelsRequest(): array
    {
        $metadata = new ModelMetadata(
            self::MODEL_ID,
            'Microsoft 365 Copilot',
            [
                CapabilityEnum::textGeneration(),
                CapabilityEnum::chatHistory(),
            ],
            /*
             * Only options the Chat API can actually honour are advertised. Declaring support for
             * temperature, max tokens, stop sequences, function calling or JSON schema output
             * would cause the AI Client to route prompts here that the endpoint silently ignores
             * or rejects; leaving them out means such prompts select a different provider instead.
             *
             * `systemInstruction` and `webSearch` are honoured, though indirectly: the system
             * instruction is delivered as grounding context and web search maps onto the
             * `contextualResources.webContext` toggle.
             */
            [
                new SupportedOption(OptionEnum::systemInstruction()),
                new SupportedOption(OptionEnum::webSearch()),
                new SupportedOption(OptionEnum::customOptions()),
                new SupportedOption(OptionEnum::inputModalities(), [[ModalityEnum::text()]]),
                new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::text()]]),
            ]
        );

        return [self::MODEL_ID => $metadata];
    }
}
