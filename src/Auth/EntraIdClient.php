<?php

declare(strict_types=1);

namespace WordPress\MicrosoftCopilotAiProvider\Auth;

use WP_Error;

/**
 * Minimal Microsoft Entra ID (Azure AD) OAuth 2.0 authorization code client.
 *
 * The Microsoft 365 Copilot Chat API supports delegated permissions only, so the plugin has to
 * obtain and refresh a token on behalf of an individual signed-in user. There is no app-only
 * alternative; see the permissions table in the Chat API reference.
 *
 * @since 0.1.0
 */
class EntraIdClient
{
    /**
     * Graph delegated permissions required by the Copilot Chat API.
     *
     * Microsoft's reference states that all of these are needed for a call to succeed;
     * `offline_access` is additionally required to receive a refresh token.
     *
     * @since 0.1.0
     *
     * @var list<string>
     */
    public const SCOPES = [
        'offline_access',
        'openid',
        'profile',
        'https://graph.microsoft.com/Sites.Read.All',
        'https://graph.microsoft.com/Mail.Read',
        'https://graph.microsoft.com/People.Read.All',
        'https://graph.microsoft.com/OnlineMeetingTranscript.Read.All',
        'https://graph.microsoft.com/Chat.Read',
        'https://graph.microsoft.com/ChannelMessage.Read.All',
        'https://graph.microsoft.com/ExternalItem.Read.All',
    ];

    /**
     * @var Settings The app registration settings.
     */
    private Settings $settings;

    /**
     * Constructor.
     *
     * @since 0.1.0
     *
     * @param Settings|null $settings Optional settings instance.
     */
    public function __construct(?Settings $settings = null)
    {
        $this->settings = $settings ?? new Settings();
    }

    /**
     * Gets the redirect URI registered with the Entra ID application.
     *
     * @since 0.1.0
     *
     * @return string The absolute redirect URI.
     */
    public function getRedirectUri(): string
    {
        return admin_url('admin-post.php?action=ai_provider_microsoft_copilot_callback');
    }

    /**
     * Builds the authorization URL that starts the sign-in flow.
     *
     * @since 0.1.0
     *
     * @param string $state Opaque state value echoed back by Microsoft.
     * @return string The authorization URL.
     */
    public function getAuthorizationUrl(string $state): string
    {
        $query = [
            'client_id' => $this->settings->getClientId(),
            'response_type' => 'code',
            'redirect_uri' => $this->getRedirectUri(),
            'response_mode' => 'query',
            'scope' => implode(' ', self::SCOPES),
            'state' => $state,
        ];

        return $this->getEndpoint('authorize') . '?' . http_build_query($query);
    }

    /**
     * Exchanges an authorization code for a token set.
     *
     * @since 0.1.0
     *
     * @param string $code The authorization code returned by Microsoft.
     * @return array{access_token: string, refresh_token: string, expires_in: int}|WP_Error
     */
    public function exchangeCode(string $code)
    {
        return $this->requestToken([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->getRedirectUri(),
        ]);
    }

    /**
     * Exchanges a refresh token for a fresh token set.
     *
     * @since 0.1.0
     *
     * @param string $refreshToken The stored refresh token.
     * @return array{access_token: string, refresh_token: string, expires_in: int}|WP_Error
     */
    public function refresh(string $refreshToken)
    {
        return $this->requestToken([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
    }

    /**
     * Looks up the display name of the signed-in Microsoft account.
     *
     * @since 0.1.0
     *
     * @param string $accessToken A valid Graph access token.
     * @return string The display name or UPN, or an empty string if it cannot be read.
     */
    public function fetchAccountName(string $accessToken): string
    {
        $response = wp_remote_get(
            'https://graph.microsoft.com/v1.0/me',
            [
                'timeout' => 15,
                'headers' => ['Authorization' => 'Bearer ' . $accessToken],
            ]
        );

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return '';
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if (!is_array($body)) {
            return '';
        }

        foreach (['userPrincipalName', 'displayName', 'mail'] as $key) {
            if (isset($body[$key]) && is_string($body[$key]) && $body[$key] !== '') {
                return $body[$key];
            }
        }

        return '';
    }

    /**
     * Posts to the Entra ID token endpoint and normalizes the response.
     *
     * @since 0.1.0
     *
     * @param array<string, string> $body Grant-specific request parameters.
     * @return array{access_token: string, refresh_token: string, expires_in: int}|WP_Error
     */
    private function requestToken(array $body)
    {
        if (!$this->settings->isConfigured()) {
            return new WP_Error(
                'ai_provider_microsoft_copilot_not_configured',
                __(
                    'The Microsoft Entra ID application is not fully configured.',
                    'ai-provider-for-microsoft-copilot'
                )
            );
        }

        $body['client_id'] = $this->settings->getClientId();
        $body['client_secret'] = $this->settings->getClientSecret();
        $body['scope'] = implode(' ', self::SCOPES);

        $response = wp_remote_post(
            $this->getEndpoint('token'),
            [
                'timeout' => 20,
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
                'body' => $body,
            ]
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $data = json_decode((string) wp_remote_retrieve_body($response), true);

        if (!is_array($data)) {
            return new WP_Error(
                'ai_provider_microsoft_copilot_bad_token_response',
                __('The Microsoft token endpoint returned an unreadable response.', 'ai-provider-for-microsoft-copilot')
            );
        }

        if (wp_remote_retrieve_response_code($response) !== 200 || !isset($data['access_token'])) {
            $description = isset($data['error_description']) && is_string($data['error_description'])
                ? $data['error_description']
                : __('Unknown error.', 'ai-provider-for-microsoft-copilot');

            return new WP_Error('ai_provider_microsoft_copilot_token_error', $description);
        }

        return [
            'access_token' => (string) $data['access_token'],
            /*
             * Entra ID omits refresh_token on some refresh responses, in which case the previously
             * stored one remains valid and the caller is expected to keep using it.
             */
            'refresh_token' => isset($data['refresh_token']) ? (string) $data['refresh_token'] : '',
            'expires_in' => isset($data['expires_in']) ? (int) $data['expires_in'] : 3600,
        ];
    }

    /**
     * Builds a tenant-specific OAuth endpoint URL.
     *
     * @since 0.1.0
     *
     * @param string $endpoint Either 'authorize' or 'token'.
     * @return string The endpoint URL.
     */
    private function getEndpoint(string $endpoint): string
    {
        return sprintf(
            'https://login.microsoftonline.com/%s/oauth2/v2.0/%s',
            rawurlencode($this->settings->getTenantId()),
            $endpoint
        );
    }
}
