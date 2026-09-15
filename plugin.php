<?php

/**
 * Plugin Name:       AI Provider for Microsoft Copilot
 * Plugin URI:        https://github.com/alansmodic/ai-provider-for-microsoft-copilot
 * Description:       Microsoft 365 Copilot provider for the WordPress AI Client. Each user connects their own Microsoft work account.
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Version:           0.1.0
 * Author:            Alan Smodic
 * License:           GPL-2.0-or-later
 * License URI:       https://spdx.org/licenses/GPL-2.0-or-later.html
 * Text Domain:       ai-provider-for-microsoft-copilot
 *
 * @package WordPress\MicrosoftCopilotAiProvider
 */

declare(strict_types=1);

namespace WordPress\MicrosoftCopilotAiProvider;

use WordPress\AiClient\AiClient;
use WordPress\MicrosoftCopilotAiProvider\Admin\ConnectionController;
use WordPress\MicrosoftCopilotAiProvider\Admin\SettingsPage;
use WordPress\MicrosoftCopilotAiProvider\Provider\CopilotProvider;

if (!defined('ABSPATH')) {
    return;
}

define('AI_PROVIDER_MICROSOFT_COPILOT_FILE', __FILE__);
define('AI_PROVIDER_MICROSOFT_COPILOT_DIR', __DIR__);

require_once __DIR__ . '/src/autoload.php';

/**
 * Registers the Microsoft Copilot provider with the AI Client.
 *
 * @since 0.1.0
 *
 * @return void
 */
function register_provider(): void
{
    if (!class_exists(AiClient::class)) {
        return;
    }

    $registry = AiClient::defaultRegistry();

    if ($registry->hasProvider(CopilotProvider::class)) {
        return;
    }

    $registry->registerProvider(CopilotProvider::class);
}

add_action('init', __NAMESPACE__ . '\\register_provider', 5);

/**
 * Boots the admin surfaces: the settings screen and the OAuth connection endpoints.
 *
 * @since 0.1.0
 *
 * @return void
 */
function bootstrap_admin(): void
{
    (new SettingsPage())->register();
    (new ConnectionController())->register();
}

add_action('plugins_loaded', __NAMESPACE__ . '\\bootstrap_admin');

/**
 * Surfaces an admin notice when the required PHP AI Client is missing.
 *
 * @since 0.1.0
 *
 * @return void
 */
function maybe_render_dependency_notice(): void
{
    if (class_exists(AiClient::class) || !current_user_can('activate_plugins')) {
        return;
    }

    printf(
        '<div class="notice notice-error"><p>%s</p></div>',
        esc_html__(
            'AI Provider for Microsoft Copilot requires the PHP AI Client (bundled with WordPress 7.0+, or installable as a plugin on 6.9).',
            'ai-provider-for-microsoft-copilot'
        )
    );
}

add_action('admin_notices', __NAMESPACE__ . '\\maybe_render_dependency_notice');
