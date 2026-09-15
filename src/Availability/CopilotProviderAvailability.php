<?php

declare(strict_types=1);

namespace WordPress\MicrosoftCopilotAiProvider\Availability;

use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\MicrosoftCopilotAiProvider\Auth\Settings;
use WordPress\MicrosoftCopilotAiProvider\Auth\TokenStore;

/**
 * Reports whether Microsoft 365 Copilot is usable for the current request.
 *
 * Availability is per-user by design: the site-wide app registration is only half of what is
 * needed, because the Chat API will not accept a call unless the person making it has connected
 * their own licensed Microsoft work account.
 *
 * @since 0.1.0
 */
class CopilotProviderAvailability implements ProviderAvailabilityInterface
{
    /**
     * @var Settings The app registration settings.
     */
    private Settings $settings;

    /**
     * @var TokenStore The per-user token storage.
     */
    private TokenStore $store;

    /**
     * Constructor.
     *
     * @since 0.1.0
     *
     * @param Settings|null $settings Optional settings instance.
     * @param TokenStore|null $store Optional token store.
     */
    public function __construct(?Settings $settings = null, ?TokenStore $store = null)
    {
        $this->settings = $settings ?? new Settings();
        $this->store = $store ?? new TokenStore();
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    public function isConfigured(): bool
    {
        if (!$this->settings->isConfigured()) {
            return false;
        }

        $userId = get_current_user_id();

        return $userId > 0 && $this->store->isConnected($userId);
    }
}
