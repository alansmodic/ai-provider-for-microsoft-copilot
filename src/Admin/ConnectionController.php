<?php

declare(strict_types=1);

namespace WordPress\MicrosoftCopilotAiProvider\Admin;

use WordPress\MicrosoftCopilotAiProvider\Auth\EntraIdClient;
use WordPress\MicrosoftCopilotAiProvider\Auth\Settings;
use WordPress\MicrosoftCopilotAiProvider\Auth\TokenStore;

/**
 * Handles the OAuth sign-in round trip for connecting a user's Microsoft account.
 *
 * @since 0.1.0
 */
class ConnectionController
{
    private const STATE_TRANSIENT_PREFIX = 'ai_provider_ms_copilot_state_';

    /**
     * @var EntraIdClient The OAuth client.
     */
    private EntraIdClient $client;

    /**
     * @var TokenStore The per-user token storage.
     */
    private TokenStore $store;

    /**
     * Constructor.
     *
     * @since 0.1.0
     *
     * @param EntraIdClient|null $client Optional OAuth client.
     * @param TokenStore|null $store Optional token store.
     */
    public function __construct(?EntraIdClient $client = null, ?TokenStore $store = null)
    {
        $this->client = $client ?? new EntraIdClient(new Settings());
        $this->store = $store ?? new TokenStore();
    }

    /**
     * Hooks the admin-post handlers.
     *
     * @since 0.1.0
     *
     * @return void
     */
    public function register(): void
    {
        add_action('admin_post_ai_provider_microsoft_copilot_connect', [$this, 'handleConnect']);
        add_action('admin_post_ai_provider_microsoft_copilot_disconnect', [$this, 'handleDisconnect']);
        add_action('admin_post_ai_provider_microsoft_copilot_callback', [$this, 'handleCallback']);
    }

    /**
     * Starts the sign-in flow by redirecting to Microsoft.
     *
     * @since 0.1.0
     *
     * @return void
     */
    public function handleConnect(): void
    {
        $userId = $this->requireUser('ai_provider_microsoft_copilot_connect');

        /*
         * The state value is both a CSRF guard and the means of recovering which WordPress user
         * started the flow, since Microsoft returns to a plain admin-post URL. It is stored
         * server side and consumed once.
         */
        $state = wp_generate_password(32, false);

        set_transient(self::STATE_TRANSIENT_PREFIX . $state, $userId, 15 * MINUTE_IN_SECONDS);

        /*
         * wp_safe_redirect() is deliberately not used here: the whole point of this redirect is to
         * leave the site for login.microsoftonline.com, which its allow-list would block.
         */
        // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
        wp_redirect($this->client->getAuthorizationUrl($state));
        exit;
    }

    /**
     * Removes a user's stored tokens.
     *
     * @since 0.1.0
     *
     * @return void
     */
    public function handleDisconnect(): void
    {
        $userId = $this->requireUser('ai_provider_microsoft_copilot_disconnect');

        $this->store->delete($userId);

        $this->redirectBack('disconnected');
    }

    /**
     * Receives the authorization code from Microsoft and stores the resulting tokens.
     *
     * @since 0.1.0
     *
     * @return void
     */
    public function handleCallback(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- The state parameter serves this role.
        $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash((string) $_GET['state'])) : '';
        $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash((string) $_GET['code'])) : '';
        $error = isset($_GET['error_description'])
            ? sanitize_text_field(wp_unslash((string) $_GET['error_description']))
            : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $transientKey = self::STATE_TRANSIENT_PREFIX . $state;
        $storedUserId = $state !== '' ? get_transient($transientKey) : false;
        $userId = is_numeric($storedUserId) ? (int) $storedUserId : 0;

        if ($userId <= 0) {
            wp_die(
                esc_html__(
                    'The Microsoft sign-in link has expired or was already used. Please try connecting again.',
                    'ai-provider-for-microsoft-copilot'
                )
            );
        }

        delete_transient($transientKey);

        if ($userId !== get_current_user_id()) {
            wp_die(
                esc_html__(
                    'This sign-in was started by a different WordPress user.',
                    'ai-provider-for-microsoft-copilot'
                )
            );
        }

        if ($error !== '' || $code === '') {
            $this->redirectBack('error', $error);

            return;
        }

        $tokens = $this->client->exchangeCode($code);

        if (is_wp_error($tokens)) {
            $this->redirectBack('error', $tokens->get_error_message());

            return;
        }

        $this->store->save(
            $userId,
            $tokens['access_token'],
            $tokens['refresh_token'],
            $tokens['expires_in'],
            $this->client->fetchAccountName($tokens['access_token'])
        );

        $this->redirectBack('connected');
    }

    /**
     * Verifies the nonce and signed-in state for an action, returning the acting user ID.
     *
     * @since 0.1.0
     *
     * @param string $action The nonce action name.
     * @return int The current user ID.
     */
    private function requireUser(string $action): int
    {
        if (!is_user_logged_in()) {
            wp_die(esc_html__('You must be signed in.', 'ai-provider-for-microsoft-copilot'));
        }

        check_admin_referer($action);

        return get_current_user_id();
    }

    /**
     * Redirects back to the settings screen with a status message.
     *
     * @since 0.1.0
     *
     * @param string $status The status slug.
     * @param string $message Optional error detail.
     * @return void
     */
    private function redirectBack(string $status, string $message = ''): void
    {
        $args = [
            'page' => SettingsPage::PAGE_SLUG,
            'copilot_status' => $status,
        ];

        if ($message !== '') {
            $args['copilot_message'] = rawurlencode($message);
        }

        wp_safe_redirect(add_query_arg($args, admin_url('options-general.php')));
        exit;
    }
}
