<?php

declare(strict_types=1);

namespace WordPress\MicrosoftCopilotAiProvider\Auth;

use WP_Error;

/**
 * Resolves a usable Graph access token for a WordPress user, refreshing it when it has lapsed.
 *
 * @since 0.1.0
 */
class TokenProvider
{
    /**
     * OAuth error codes that mean the stored grant is genuinely dead.
     *
     * Anything outside this list -- a timeout, a DNS failure, a 503 -- is treated as transient and
     * leaves the stored tokens alone, so a momentary network problem cannot silently unlink a
     * working account.
     *
     * @link https://learn.microsoft.com/entra/identity-platform/reference-error-codes
     *
     * @since 0.2.0
     *
     * @var list<string>
     */
    private const TERMINAL_OAUTH_ERRORS = [
        'invalid_grant',
        'invalid_client',
        'unauthorized_client',
        'interaction_required',
        'consent_required',
        'login_required',
    ];

    /**
     * @var TokenStore The per-user token storage.
     */
    private TokenStore $store;

    /**
     * @var EntraIdClient The OAuth client.
     */
    private EntraIdClient $client;

    /**
     * Constructor.
     *
     * @since 0.1.0
     *
     * @param TokenStore|null $store Optional token store.
     * @param EntraIdClient|null $client Optional OAuth client.
     */
    public function __construct(?TokenStore $store = null, ?EntraIdClient $client = null)
    {
        $this->store = $store ?? new TokenStore();
        $this->client = $client ?? new EntraIdClient();
    }

    /**
     * Gets a valid access token for a user.
     *
     * @since 0.1.0
     *
     * @param int|null $userId The user ID, or null for the current user.
     * @return string|null A valid access token, or null if the user has no usable connection.
     */
    public function getAccessToken(?int $userId = null): ?string
    {
        $userId = $userId ?? get_current_user_id();

        if ($userId <= 0) {
            return null;
        }

        $token = $this->store->getAccessToken($userId);

        if ($token !== null) {
            return $token;
        }

        return $this->refreshFor($userId);
    }

    /**
     * Attempts to renew a user's access token from their stored refresh token.
     *
     * @since 0.1.0
     *
     * @param int $userId The user ID.
     * @return string|null The new access token, or null if renewal failed.
     */
    private function refreshFor(int $userId): ?string
    {
        $refreshToken = $this->store->getRefreshToken($userId);

        if ($refreshToken === null) {
            return null;
        }

        $tokens = $this->client->refresh($refreshToken);

        if (is_wp_error($tokens)) {
            if ($this->isTerminalFailure($tokens)) {
                /*
                 * Microsoft rotates refresh tokens, so two requests refreshing at once will see
                 * one succeed and the other rejected as an invalid grant. Re-reading storage
                 * before discarding it distinguishes that race -- where the stored token has
                 * already moved on and the connection is healthy -- from a grant that really was
                 * revoked.
                 */
                $currentToken = $this->store->getRefreshToken($userId);

                if ($currentToken !== null && $currentToken !== $refreshToken) {
                    return $this->store->getAccessToken($userId);
                }

                $this->store->delete($userId);
            }

            return null;
        }

        /*
         * A failure to persist is not a failure to authenticate: the token just obtained is still
         * good for this request, and the next one will refresh again rather than break.
         */
        $this->store->save(
            $userId,
            $tokens['access_token'],
            $tokens['refresh_token'] !== '' ? $tokens['refresh_token'] : $refreshToken,
            $tokens['expires_in'],
            $this->store->getAccountName($userId)
        );

        return $tokens['access_token'];
    }

    /**
     * Determines whether a refresh failure means the stored grant should be discarded.
     *
     * @since 0.2.0
     *
     * @param WP_Error $error The error returned by the token endpoint.
     * @return bool True if the grant is dead and re-authentication is required.
     */
    private function isTerminalFailure(WP_Error $error): bool
    {
        $data = $error->get_error_data();
        $oauthError = is_array($data) && isset($data['oauth_error']) && is_string($data['oauth_error'])
            ? $data['oauth_error']
            : '';

        return in_array($oauthError, self::TERMINAL_OAUTH_ERRORS, true);
    }
}
