<?php

declare(strict_types=1);

namespace WordPress\MicrosoftCopilotAiProvider\Auth;

/**
 * Accessor for the Entra ID application registration settings.
 *
 * Values may come from the options table or, preferably on production sites, from constants
 * defined in wp-config.php so that the client secret never lives in the database.
 *
 * @since 0.1.0
 */
class Settings
{
    public const OPTION_NAME = 'ai_provider_microsoft_copilot_settings';

    /**
     * Gets the Entra ID directory (tenant) ID.
     *
     * @since 0.1.0
     *
     * @return string The tenant ID, or an empty string if unset.
     */
    public function getTenantId(): string
    {
        if (defined('MICROSOFT_COPILOT_TENANT_ID') && is_string(MICROSOFT_COPILOT_TENANT_ID)) {
            return MICROSOFT_COPILOT_TENANT_ID;
        }

        return $this->getOption('tenant_id');
    }

    /**
     * Gets the Entra ID application (client) ID.
     *
     * @since 0.1.0
     *
     * @return string The client ID, or an empty string if unset.
     */
    public function getClientId(): string
    {
        if (defined('MICROSOFT_COPILOT_CLIENT_ID') && is_string(MICROSOFT_COPILOT_CLIENT_ID)) {
            return MICROSOFT_COPILOT_CLIENT_ID;
        }

        return $this->getOption('client_id');
    }

    /**
     * Gets the Entra ID application client secret.
     *
     * @since 0.1.0
     *
     * @return string The client secret, or an empty string if unset.
     */
    public function getClientSecret(): string
    {
        if (defined('MICROSOFT_COPILOT_CLIENT_SECRET') && is_string(MICROSOFT_COPILOT_CLIENT_SECRET)) {
            return MICROSOFT_COPILOT_CLIENT_SECRET;
        }

        return $this->getOption('client_secret');
    }

    /**
     * Determines whether the app registration is complete enough to attempt a sign-in.
     *
     * @since 0.1.0
     *
     * @return bool True if tenant, client ID and client secret are all present.
     */
    public function isConfigured(): bool
    {
        return $this->getTenantId() !== ''
            && $this->getClientId() !== ''
            && $this->getClientSecret() !== '';
    }

    /**
     * Determines whether a given setting is locked by a wp-config.php constant.
     *
     * @since 0.1.0
     *
     * @param string $key One of 'tenant_id', 'client_id' or 'client_secret'.
     * @return bool True if a constant supplies the value.
     */
    public function isDefinedByConstant(string $key): bool
    {
        $constants = [
            'tenant_id' => 'MICROSOFT_COPILOT_TENANT_ID',
            'client_id' => 'MICROSOFT_COPILOT_CLIENT_ID',
            'client_secret' => 'MICROSOFT_COPILOT_CLIENT_SECRET',
        ];

        return isset($constants[$key]) && defined($constants[$key]);
    }

    /**
     * Reads a single value from the stored settings array.
     *
     * @since 0.1.0
     *
     * @param string $key The settings key.
     * @return string The stored value, or an empty string.
     */
    private function getOption(string $key): string
    {
        $settings = get_option(self::OPTION_NAME, []);

        if (!is_array($settings) || !isset($settings[$key]) || !is_string($settings[$key])) {
            return '';
        }

        return $settings[$key];
    }
}
