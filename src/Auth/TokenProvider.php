<?php

declare(strict_types=1);

namespace WordPress\MicrosoftCopilotAiProvider\Auth;

/**
 * Resolves a usable Graph access token for a WordPress user, refreshing it when it has lapsed.
 *
 * @since 0.1.0
 */
class TokenProvider
{
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
            /*
             * A refresh token is rejected once consent is revoked, the password changes, or the
             * token simply ages out. Dropping the stored set means the connection is reported as
             * absent and the user is prompted to sign in again rather than seeing repeated errors.
             */
            $this->store->delete($userId);

            return null;
        }

        $this->store->save(
            $userId,
            $tokens['access_token'],
            $tokens['refresh_token'] !== '' ? $tokens['refresh_token'] : $refreshToken,
            $tokens['expires_in'],
            $this->store->getAccountName($userId)
        );

        return $tokens['access_token'];
    }
}
