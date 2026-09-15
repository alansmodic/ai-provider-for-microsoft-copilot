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
 * Tokens are encrypted at rest with libsodium's authenticated encryption, keyed from the site's
 * auth salt. A refresh token here grants read access to the owner's mail, files and Teams
 * messages, so storage that cannot be encrypted is refused rather than written in the clear.
 *
 * @since 0.1.0
 */
class TokenStore
{
    public const META_KEY = '_ai_provider_microsoft_copilot_tokens';

    /**
     * Prefix marking a value encrypted with sodium's secretbox.
     *
     * @since 0.2.0
     *
     * @var string
     */
    private const PREFIX_SODIUM = 'sodium:';

    /**
     * Prefixes written by 0.1.0, still readable so existing connections survive an upgrade.
     *
     * @since 0.2.0
     *
     * @var string
     */
    private const PREFIX_LEGACY_CBC = 'enc:';
    private const PREFIX_LEGACY_PLAIN = 'plain:';

    private const LEGACY_CIPHER = 'aes-256-cbc';

    /**
     * Determines whether tokens can be stored securely on this installation.
     *
     * @since 0.2.0
     *
     * @return bool True if libsodium and a usable salt are both present.
     */
    public function isAvailable(): bool
    {
        return function_exists('sodium_crypto_secretbox') && $this->getEncryptionKey() !== '';
    }

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
     * @return bool True if the tokens were stored, false if encryption was unavailable.
     */
    public function save(
        int $userId,
        string $accessToken,
        string $refreshToken,
        int $expiresIn,
        string $accountName = ''
    ): bool {
        $encryptedAccess = $this->encrypt($accessToken);
        $encryptedRefresh = $this->encrypt($refreshToken);

        if ($encryptedAccess === null || $encryptedRefresh === null) {
            return false;
        }

        /*
         * A 60 second safety margin keeps a token that is about to lapse from being sent on a
         * request that then fails midway through generation.
         */
        update_user_meta(
            $userId,
            self::META_KEY,
            [
                'access_token' => $encryptedAccess,
                'refresh_token' => $encryptedRefresh,
                'expires_at' => time() + max(0, $expiresIn - 60),
                'account_name' => $accountName,
            ]
        );

        return true;
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

        /*
         * The expiry is read with is_numeric rather than is_int: meta that has been through a
         * JSON-based export and import comes back as a string, and treating that as a missing
         * expiry would force a token refresh on every single request.
         */
        $expiresAt = $payload['expires_at'] ?? null;

        return [
            'access_token' => is_string($payload['access_token'] ?? null) ? $payload['access_token'] : '',
            'refresh_token' => is_string($payload['refresh_token']) ? $payload['refresh_token'] : '',
            'expires_at' => is_numeric($expiresAt) ? (int) $expiresAt : 0,
            'account_name' => is_string($payload['account_name'] ?? null) ? $payload['account_name'] : '',
        ];
    }

    /**
     * Encrypts a token for storage.
     *
     * @since 0.1.0
     *
     * @param string $value The plaintext token.
     * @return string|null The stored representation, or null if encryption is unavailable.
     */
    private function encrypt(string $value): ?string
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($value, $nonce, $this->getEncryptionKey());

        return self::PREFIX_SODIUM . base64_encode($nonce . $ciphertext);
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
        if (strpos($stored, self::PREFIX_SODIUM) === 0) {
            return $this->decryptSodium(substr($stored, strlen(self::PREFIX_SODIUM)));
        }

        // Values written by 0.1.0. Read only; nothing writes these formats any more.
        if (strpos($stored, self::PREFIX_LEGACY_PLAIN) === 0) {
            return substr($stored, strlen(self::PREFIX_LEGACY_PLAIN));
        }

        if (strpos($stored, self::PREFIX_LEGACY_CBC) === 0) {
            return $this->decryptLegacyCbc(substr($stored, strlen(self::PREFIX_LEGACY_CBC)));
        }

        return '';
    }

    /**
     * Decrypts a secretbox payload.
     *
     * @since 0.2.0
     *
     * @param string $encoded Base64 of nonce followed by ciphertext.
     * @return string The plaintext, or an empty string if it cannot be authenticated.
     */
    private function decryptSodium(string $encoded): string
    {
        if (!$this->isAvailable()) {
            return '';
        }

        $raw = base64_decode($encoded, true);

        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }

        $plaintext = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->getEncryptionKey()
        );

        // A false return means the ciphertext failed authentication and must not be trusted.
        return is_string($plaintext) ? $plaintext : '';
    }

    /**
     * Decrypts a value written by 0.1.0's unauthenticated AES-256-CBC path.
     *
     * @since 0.2.0
     *
     * @param string $encoded Base64 of IV followed by ciphertext.
     * @return string The plaintext, or an empty string on failure.
     */
    private function decryptLegacyCbc(string $encoded): string
    {
        if (!function_exists('openssl_decrypt') || !defined('AUTH_KEY') || !is_string(AUTH_KEY)) {
            return '';
        }

        $raw = base64_decode($encoded, true);
        $ivLength = (int) openssl_cipher_iv_length(self::LEGACY_CIPHER);

        if ($raw === false || strlen($raw) <= $ivLength) {
            return '';
        }

        $plaintext = openssl_decrypt(
            substr($raw, $ivLength),
            self::LEGACY_CIPHER,
            hash('sha256', AUTH_KEY, true),
            OPENSSL_RAW_DATA,
            substr($raw, 0, $ivLength)
        );

        return is_string($plaintext) ? $plaintext : '';
    }

    /**
     * Derives the encryption key from the site's auth salt.
     *
     * wp_salt() is preferred over the raw AUTH_KEY constant because it also covers installations
     * that keep their salts in the database rather than in wp-config.php.
     *
     * @since 0.1.0
     *
     * @return string A 32 byte key, or an empty string if no salt is available.
     */
    private function getEncryptionKey(): string
    {
        $salt = function_exists('wp_salt') ? wp_salt('auth') : '';

        if (!is_string($salt) || $salt === '') {
            return '';
        }

        return hash('sha256', $salt, true);
    }
}
