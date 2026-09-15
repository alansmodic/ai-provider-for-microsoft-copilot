<?php

declare(strict_types=1);

namespace WordPress\MicrosoftCopilotAiProvider\Admin;

use WordPress\MicrosoftCopilotAiProvider\Auth\EntraIdClient;
use WordPress\MicrosoftCopilotAiProvider\Auth\Settings;
use WordPress\MicrosoftCopilotAiProvider\Auth\TokenStore;

/**
 * Settings screen for the Entra ID application registration and the per-user connection.
 *
 * @since 0.1.0
 */
class SettingsPage
{
    public const PAGE_SLUG = 'ai-provider-microsoft-copilot';

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
     * Hooks the settings screen into the admin.
     *
     * @since 0.1.0
     *
     * @return void
     */
    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_init', [$this, 'registerSetting']);
    }

    /**
     * Registers the options page.
     *
     * @since 0.1.0
     *
     * @return void
     */
    public function addMenuPage(): void
    {
        add_options_page(
            __('Microsoft Copilot AI', 'ai-provider-for-microsoft-copilot'),
            __('Microsoft Copilot AI', 'ai-provider-for-microsoft-copilot'),
            /*
             * Anyone who may use the provider needs to reach this screen to connect their own
             * account, so the page is readable at 'read'. Writing the shared app registration is
             * gated separately inside the form itself.
             */
            'read',
            self::PAGE_SLUG,
            [$this, 'render']
        );
    }

    /**
     * Registers the settings option and its sanitizer.
     *
     * @since 0.1.0
     *
     * @return void
     */
    public function registerSetting(): void
    {
        register_setting(
            self::PAGE_SLUG,
            Settings::OPTION_NAME,
            [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitize'],
                'default' => [],
                // The settings hold a client secret and have no business in the REST API.
                'show_in_rest' => false,
            ]
        );

        $this->ensureSecretIsNotAutoloaded();
    }

    /**
     * Keeps the settings out of the autoloaded options cache.
     *
     * register_setting() has no autoload argument, and add_option() defaults to autoloading, so
     * without this the client secret would be read into memory on every single page load of the
     * site, front end included.
     *
     * @since 0.2.0
     *
     * @return void
     */
    private function ensureSecretIsNotAutoloaded(): void
    {
        if (get_option(Settings::OPTION_NAME) === false) {
            return;
        }

        wp_set_option_autoload(Settings::OPTION_NAME, false);
    }

    /**
     * Sanitizes submitted settings.
     *
     * @since 0.1.0
     *
     * @param mixed $input The raw submitted value.
     * @return array<string, string> The sanitized settings.
     */
    public function sanitize($input): array
    {
        $existing = get_option(Settings::OPTION_NAME, []);
        $existing = is_array($existing) ? $existing : [];

        /*
         * options.php checks the option group capability before reaching this point, but the
         * sanitizer is a public callback reachable through any update_option() of this option,
         * so it verifies the capability itself rather than trusting the caller.
         */
        if (!current_user_can('manage_options')) {
            return $existing;
        }

        if (!is_array($input)) {
            return $existing;
        }

        $clean = $existing;

        foreach (['tenant_id', 'client_id'] as $key) {
            if (isset($input[$key])) {
                $clean[$key] = sanitize_text_field((string) $input[$key]);
            }
        }

        /*
         * The secret field renders empty so that the stored value is never echoed back into the
         * page source. An empty submission therefore means "leave unchanged" rather than "clear".
         */
        if (isset($input['client_secret']) && trim((string) $input['client_secret']) !== '') {
            $clean['client_secret'] = trim((string) $input['client_secret']);
        }

        return $clean;
    }

    /**
     * Renders the settings screen.
     *
     * @since 0.1.0
     *
     * @return void
     */
    public function render(): void
    {
        $canManage = current_user_can('manage_options');
        $userId = get_current_user_id();
        $connected = $this->store->isConnected($userId);
        $client = new EntraIdClient($this->settings);

        echo '<div class="wrap">';
        printf('<h1>%s</h1>', esc_html__('Microsoft Copilot AI', 'ai-provider-for-microsoft-copilot'));

        // A custom options page does not get the "Settings saved." notice for free.
        settings_errors(self::PAGE_SLUG);

        $this->renderStatusNotice();
        $this->renderRequirementsNotice();
        $this->renderConnectionSection($userId, $connected);

        if ($canManage) {
            $this->renderApplicationForm($client);
        }

        echo '</div>';
    }

    /**
     * Renders the outcome of a just-completed connect or disconnect action.
     *
     * @since 0.1.0
     *
     * @return void
     */
    private function renderStatusNotice(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only status flag.
        $status = isset($_GET['copilot_status'])
            ? sanitize_key(wp_unslash((string) $_GET['copilot_status']))
            : '';
        $rawMessage = isset($_GET['copilot_message'])
            ? sanitize_text_field(wp_unslash((string) $_GET['copilot_message']))
            : '';
        // Sanitized again after decoding, since percent-decoding can reveal characters the first pass never saw.
        $message = $rawMessage !== '' ? sanitize_text_field(rawurldecode($rawMessage)) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if ($status === '') {
            return;
        }

        if ($status === 'error') {
            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                esc_html(
                    $message !== ''
                        ? sprintf(
                            /* translators: %s: error message from Microsoft. */
                            __('Could not connect the Microsoft account: %s', 'ai-provider-for-microsoft-copilot'),
                            $message
                        )
                        : __('Could not connect the Microsoft account.', 'ai-provider-for-microsoft-copilot')
                )
            );

            return;
        }

        $messages = [
            'connected' => __('Microsoft account connected.', 'ai-provider-for-microsoft-copilot'),
            'disconnected' => __('Microsoft account disconnected.', 'ai-provider-for-microsoft-copilot'),
        ];

        if (isset($messages[$status])) {
            printf('<div class="notice notice-success"><p>%s</p></div>', esc_html($messages[$status]));
        }
    }

    /**
     * Renders the standing caveats about the Copilot Chat API.
     *
     * @since 0.1.0
     *
     * @return void
     */
    private function renderRequirementsNotice(): void
    {
        /*
         * Kept as two sentences rather than one paragraph-length string: shorter strings are
         * easier to translate, and the two facts are independently useful.
         */
        echo '<div class="notice notice-info inline"><p>';
        echo esc_html__(
            'Copilot supports delegated permissions only, so each person connects their own Microsoft account.',
            'ai-provider-for-microsoft-copilot'
        );
        echo ' ';
        echo esc_html__(
            'That account needs a Microsoft 365 Copilot add-on license.',
            'ai-provider-for-microsoft-copilot'
        );
        echo ' ';
        echo esc_html__(
            'The API is published on Microsoft Graph beta, which Microsoft does not support for production use.',
            'ai-provider-for-microsoft-copilot'
        );
        echo '</p></div>';
    }

    /**
     * Renders the per-user connect/disconnect controls.
     *
     * @since 0.1.0
     *
     * @param int $userId The current user ID.
     * @param bool $connected Whether the user has a stored connection.
     * @return void
     */
    private function renderConnectionSection(int $userId, bool $connected): void
    {
        printf('<h2>%s</h2>', esc_html__('Your Microsoft account', 'ai-provider-for-microsoft-copilot'));

        if (!$this->store->isAvailable()) {
            printf(
                '<div class="notice notice-error inline"><p>%s</p></div>',
                esc_html__(
                    'Accounts cannot be connected: encrypting tokens needs the PHP sodium extension and an auth salt.',
                    'ai-provider-for-microsoft-copilot'
                )
            );

            return;
        }

        if (!$this->settings->isConfigured()) {
            printf(
                '<p>%s</p>',
                esc_html__(
                    'An administrator must finish the Entra ID setup below before accounts can be connected.',
                    'ai-provider-for-microsoft-copilot'
                )
            );

            return;
        }

        if ($connected) {
            $accountName = $this->store->getAccountName($userId);

            printf(
                '<p>%s</p>',
                $accountName !== ''
                    ? esc_html(
                        sprintf(
                            /* translators: %s: Microsoft account name. */
                            __('Connected as %s.', 'ai-provider-for-microsoft-copilot'),
                            $accountName
                        )
                    )
                    : esc_html__('Connected.', 'ai-provider-for-microsoft-copilot')
            );

            printf(
                '<p><a class="button" href="%s">%s</a></p>',
                esc_url($this->getActionUrl('disconnect')),
                esc_html__('Disconnect', 'ai-provider-for-microsoft-copilot')
            );

            return;
        }

        printf(
            '<p>%s</p>',
            esc_html__(
                'Sign in with your Microsoft work account to let this site use Copilot on your behalf.',
                'ai-provider-for-microsoft-copilot'
            )
        );

        printf(
            '<p><a class="button button-primary" href="%s">%s</a></p>',
            esc_url($this->getActionUrl('connect')),
            esc_html__('Connect Microsoft account', 'ai-provider-for-microsoft-copilot')
        );
    }

    /**
     * Renders the shared Entra ID application registration form.
     *
     * @since 0.1.0
     *
     * @param EntraIdClient $client The OAuth client, used to display the redirect URI.
     * @return void
     */
    private function renderApplicationForm(EntraIdClient $client): void
    {
        printf(
            '<h2>%s</h2>',
            esc_html__('Entra ID application', 'ai-provider-for-microsoft-copilot')
        );

        printf(
            '<p>%s<br><code>%s</code></p>',
            esc_html__('Add this redirect URI to your app registration:', 'ai-provider-for-microsoft-copilot'),
            esc_html($client->getRedirectUri())
        );

        echo '<form action="options.php" method="post">';
        settings_fields(self::PAGE_SLUG);

        echo '<table class="form-table" role="presentation"><tbody>';

        $this->renderTextField(
            'tenant_id',
            __('Directory (tenant) ID', 'ai-provider-for-microsoft-copilot'),
            $this->settings->getTenantId()
        );
        $this->renderTextField(
            'client_id',
            __('Application (client) ID', 'ai-provider-for-microsoft-copilot'),
            $this->settings->getClientId()
        );
        $this->renderSecretField();

        echo '</tbody></table>';

        submit_button();
        echo '</form>';
    }

    /**
     * Renders a single text settings field.
     *
     * @since 0.1.0
     *
     * @param string $key The settings key.
     * @param string $label The field label.
     * @param string $value The current value.
     * @return void
     */
    private function renderTextField(string $key, string $label, string $value): void
    {
        $locked = $this->settings->isDefinedByConstant($key);

        printf(
            '<tr><th scope="row"><label for="%1$s">%2$s</label></th><td>' .
            '<input type="text" class="regular-text" id="%1$s" name="%3$s[%1$s]" value="%4$s"%5$s>',
            esc_attr($key),
            esc_html($label),
            esc_attr(Settings::OPTION_NAME),
            esc_attr($value),
            $locked ? ' disabled' : ''
        );

        if ($locked) {
            printf(
                '<p class="description">%s</p>',
                esc_html__('Defined in wp-config.php.', 'ai-provider-for-microsoft-copilot')
            );
        }

        echo '</td></tr>';
    }

    /**
     * Renders the client secret field.
     *
     * @since 0.1.0
     *
     * @return void
     */
    private function renderSecretField(): void
    {
        $locked = $this->settings->isDefinedByConstant('client_secret');
        $isSet = $this->settings->getClientSecret() !== '';

        printf(
            '<tr><th scope="row"><label for="client_secret">%1$s</label></th><td>' .
            '<input type="password" class="regular-text" id="client_secret" name="%2$s[client_secret]" ' .
            'value="" autocomplete="new-password"%3$s>',
            esc_html__('Client secret', 'ai-provider-for-microsoft-copilot'),
            esc_attr(Settings::OPTION_NAME),
            $locked ? ' disabled' : ''
        );

        if ($locked) {
            $description = __('Defined in wp-config.php.', 'ai-provider-for-microsoft-copilot');
        } elseif ($isSet) {
            $description = __('A secret is saved. Leave blank to keep it.', 'ai-provider-for-microsoft-copilot');
        } else {
            $description = __(
                'For production sites, prefer defining MICROSOFT_COPILOT_CLIENT_SECRET in wp-config.php.',
                'ai-provider-for-microsoft-copilot'
            );
        }

        printf('<p class="description">%s</p></td></tr>', esc_html($description));
    }

    /**
     * Builds a nonce-protected URL for a connection action.
     *
     * @since 0.1.0
     *
     * @param string $action Either 'connect' or 'disconnect'.
     * @return string The action URL.
     */
    private function getActionUrl(string $action): string
    {
        return wp_nonce_url(
            admin_url('admin-post.php?action=ai_provider_microsoft_copilot_' . $action),
            'ai_provider_microsoft_copilot_' . $action
        );
    }
}
