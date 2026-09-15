<?php

declare(strict_types=1);

namespace WordPress\MicrosoftCopilotAiProvider\Auth;

/**
 * Per-user storage for Microsoft Entra ID OAuth tokens.
 *
 * Tokens belong to an individual WordPress user, mirroring the Microsoft 365 Copilot Chat API's
 * delegated-only permission model: every request is made as the signed-in person, so Copilot's
 * answers stay inside that person's own document permissions.
 *
 * Refresh tokens are encrypted at rest with the site's auth salts when OpenSSL is available.
 *
 * @since 0.1.0
 */
class TokenStore
{
    public const META_KEY = '_ai_provider_microsoft_copilot_tokens';

    private const CIPHER = 'aes-256-cbc';

    /**
     * Stores a token set for a user.
     *
     * @since 0.1.0
     *
     * @param int $userId The WordPress user ID.
     * @param string $accessToken The access token.
     * @param string $refreshToken The refresh token.
     * @param int $expiresIn Lifetime of the access token, in seconds.
     * @param string $accountName Display name or UPN of the connected Microsoft account.
     * @return void
     */
    public function save(
        int $userId,
        string $accessToken,
        string $refreshToken,
        int $expiresIn,
        string $accountName = ''
    ): void {
        /*
         * A 60 second safety margin keeps a token that is about to lapse from being sent on a
         * request that then fails midway through generation.
         */
        $payload = [
            'access_token' => $this->encrypt($accessToken),
            'refresh_token' => $this->encrypt($refreshToken),
            'expires_at' => time() + max(0, $expiresIn - 60),
            'account_name' => $accountName,
        ];

        update_user_meta($userId, self::META_KEY, $payload);
    }

    /**
     * Gets the stored access token for a user, if it has not expired.
     *
     * @since 0.1.0
     *
     * @param int $userId The WordPress user ID.
     * @return string|null The access token, or null if absent or expired.
     */
    public function getAccessToken(int $userId): ?string
    {
        $payload = $this->getPayload($userId);

        if ($payload === null || $payload['expires_at'] <= time()) {
            return null;
        }

        $token = $this->decrypt($payload['access_token']);

        return $token !== '' ? $token : null;
    }

    /**
     * Gets the stored refresh token for a user.
     *
     * @since 0.1.0
     *
     * @param int $userId The WordPress user ID.
     * @return string|null The refresh token, or null if absent.
     */
    public function getRefreshToken(int $userId): ?string
    {
        $payload = $this->getPayload($userId);

        if ($payload === null) {
            return null;
        }

        $token = $this->decrypt($payload['refresh_token']);

        return $token !== '' ? $token : null;
    }

    /**
     * Gets the display name of the connected Microsoft account.
     *
     * @since 0.1.0
     *
     * @param int $userId The WordPress user ID.
     * @return string The account name, or an empty string.
     */
    public function getAccountName(int $userId): string
    {
        $payload = $this->getPayload($userId);

        return $payload['account_name'] ?? '';
    }

    /**
     * Determines whether a user has connected a Microsoft account.
     *
     * A connection counts as present while a refresh token exists, even if the access token has
     * lapsed, because the access token can be renewed without user interaction.
     *
     * @since 0.1.0
     *
     * @param int $userId The WordPress user ID.
     * @return bool True if the user has a stored refresh token.
     */
    public function isConnected(int $userId): bool
    {
        return $this->getRefreshToken($userId) !== null;
    }

    /**
     * Deletes all stored tokens for a user.
     *
     * @since 0.1.0
     *
     * @param int $userId The WordPress user ID.
     * @return void
     */
    public function delete(int $userId): void
    {
        delete_user_meta($userId, self::META_KEY);
    }

    /**
     * Reads and normalizes the stored payload for a user.
     *
     * @since 0.1.0
     *
     * @param int $userId The WordPress user ID.
     * @return array{access_token: string, refresh_token: string, expires_at: int, account_name: string}|null
     */
    private function getPayload(int $userId): ?array
    {
        $payload = get_user_meta($userId, self::META_KEY, true);

        if (!is_array($payload) || !isset($payload['refresh_token'])) {
            return null;
        }

        return [
            'access_token' => is_string($payload['access_token'] ?? null) ? $payload['access_token'] : '',
            'refresh_token' => is_string($payload['refresh_token']) ? $payload['refresh_token'] : '',
            'expires_at' => is_int($payload['expires_at'] ?? null) ? $payload['expires_at'] : 0,
            'account_name' => is_string($payload['account_name'] ?? null) ? $payload['account_name'] : '',
        ];
    }

    /**
     * Encrypts a token for storage.
     *
     * Falls back to returning the plain value when OpenSSL or the site salts are unavailable, so
     * that the plugin still works on minimal hosts. The prefix records which form was written.
     *
     * @since 0.1.0
     *
     * @param string $value The plaintext token.
     * @return string The stored representation.
     */
    private function encrypt(string $value): string
    {
        $key = $this->getEncryptionKey();

        if ($key === '' || !function_exists('openssl_encrypt')) {
            return 'plain:' . $value;
        }

        $ivLength = (int) openssl_cipher_iv_length(self::CIPHER);
        $iv = openssl_random_pseudo_bytes($ivLength);
        $ciphertext = openssl_encrypt($value, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);

        if ($ciphertext === false) {
            return 'plain:' . $value;
        }

        return 'enc:' . base64_encode($iv . $ciphertext);
    }

    /**
     * Decrypts a stored token.
     *
     * @since 0.1.0
     *
     * @param string $stored The stored representation.
     * @return string The plaintext token, or an empty string on failure.
     */
    private function decrypt(string $stored): string
    {
        if (strpos($stored, 'plain:') === 0) {
            return substr($stored, 6);
        }

        if (strpos($stored, 'enc:') !== 0) {
            return '';
        }

        $key = $this->getEncryptionKey();

        if ($key === '' || !function_exists('openssl_decrypt')) {
            return '';
        }

        $raw = base64_decode(substr($stored, 4), true);

        if ($raw === false) {
            return '';
        }

        $ivLength = (int) openssl_cipher_iv_length(self::CIPHER);

        if (strlen($raw) <= $ivLength) {
            return '';
        }

        $plaintext = openssl_decrypt(
            substr($raw, $ivLength),
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            substr($raw, 0, $ivLength)
        );

        return $plaintext === false ? '' : $plaintext;
    }

    /**
     * Derives the encryption key from the site's auth salts.
     *
     * @since 0.1.0
     *
     * @return string A 32 byte key, or an empty string if no salt is defined.
     */
    private function getEncryptionKey(): string
    {
        if (defined('AUTH_KEY') && is_string(AUTH_KEY) && AUTH_KEY !== '') {
            return hash('sha256', AUTH_KEY, true);
        }

        return '';
    }
}
