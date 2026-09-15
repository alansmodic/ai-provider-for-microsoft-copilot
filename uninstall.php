<?php

/**
 * Removes everything this plugin stored.
 *
 * The user meta cleared here holds live Microsoft Graph refresh tokens carrying read access to
 * the owner's mail, files and Teams messages, so leaving it behind after an uninstall would be a
 * privacy problem rather than mere untidiness.
 *
 * Deleting local storage does not revoke anything at Microsoft. Entra ID offers no way for a
 * confidential client to revoke another party's refresh token, so consent has to be withdrawn from
 * the Microsoft side, at https://myaccount.microsoft.com/. The readme says so.
 *
 * @since 0.2.0
 *
 * @package WordPress\MicrosoftCopilotAiProvider
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/src/autoload.php';

use WordPress\MicrosoftCopilotAiProvider\Auth\Settings;
use WordPress\MicrosoftCopilotAiProvider\Auth\TokenStore;

/**
 * Deletes the plugin's option and every user's stored tokens for the current site.
 *
 * @since 0.2.0
 *
 * @return void
 */
function ai_provider_microsoft_copilot_uninstall_site(): void
{
    delete_option(Settings::OPTION_NAME);

    /*
     * Passing an empty object ID with $delete_all removes the meta for every user in one query,
     * rather than paging through the user list.
     */
    delete_metadata('user', 0, TokenStore::META_KEY, '', true);
}

if (is_multisite()) {
    /*
     * User meta is network-global, so the token cleanup above is not per-site. The options are,
     * which is why every site still needs visiting.
     */
    $site_ids = get_sites(
        [
            'fields' => 'ids',
            'number' => 0,
        ]
    );

    foreach ($site_ids as $site_id) {
        switch_to_blog((int) $site_id);
        ai_provider_microsoft_copilot_uninstall_site();
        restore_current_blog();
    }
} else {
    ai_provider_microsoft_copilot_uninstall_site();
}
