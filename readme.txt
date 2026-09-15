=== AI Provider for Microsoft Copilot ===
Contributors: alansmodic
Tags: ai, microsoft, copilot, microsoft-365
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.2.1
License: GPL-2.0-or-later
License URI: https://spdx.org/licenses/GPL-2.0-or-later.html

Microsoft 365 Copilot provider for the WordPress AI Client. Each user connects their own Microsoft work account.

== Description ==

Registers Microsoft 365 Copilot as a provider for the WordPress AI Client, so any plugin built on the AI Client can
generate text through Copilot.

Answers are grounded in the connected person's own Microsoft 365 content, and Microsoft's permission model is preserved:
Copilot can only reach files, mail and messages that the signed-in user could already open themselves.

= Requirements =

* WordPress 6.9 or later, and PHP 7.4 or later.
* The PHP AI Client, which WordPress 7.0 and later bundle. On 6.9 it installs as a separate plugin.
* The PHP sodium extension and a defined authentication salt, used to encrypt stored tokens.
* A Microsoft Entra ID application registration you control.
* A Microsoft 365 Copilot add-on license for every person who will use the provider.
* Each user connects their own Microsoft work account under Settings > Microsoft Copilot AI.

= Known limitations =

These come from the Microsoft 365 Copilot Chat API itself, not from this plugin:

* The API supports delegated permissions only. There is no app-only mode, so background jobs and cron tasks that run
  without a signed-in user cannot use this provider.
* Personal Microsoft accounts are not supported. Work or school accounts only.
* The API is published on Microsoft Graph beta, which Microsoft states is not supported for production use.
* No model selection, temperature, token limit, stop sequences, function calling or JSON schema output.
* Text responses only. No image generation, code interpreter or file creation.
* Long-running prompts are prone to gateway timeouts.

== Privacy ==

This plugin sends prompt text to Microsoft and stores Microsoft credentials on your site.

* **What is sent.** Prompts, any prior turns of the conversation, and the system instruction are
  sent to the Microsoft 365 Copilot Chat API at graph.microsoft.com, together with the site's
  timezone. Microsoft grounds its answers in the connected user's own Microsoft 365 content.
* **What is stored.** Each connected user's OAuth access and refresh tokens are stored in user
  meta, encrypted with libsodium using the site's authentication salt, along with the display name
  of the connected Microsoft account.
* **What that grants.** A stored refresh token carries the delegated scopes the app registration
  was consented for, which include reading the owner's mail, SharePoint and OneDrive files, Teams
  chats and channel messages.
* **Removing it.** Disconnecting from Settings > Microsoft Copilot AI deletes the stored tokens.
  Uninstalling the plugin deletes the settings and every user's stored tokens. Neither revokes
  access at Microsoft: withdraw consent at https://myaccount.microsoft.com/.
* **Microsoft's terms.** Use of the Chat API is governed by the Microsoft 365 Copilot APIs Terms
  of Use.

== Installation ==

1. Make the PHP AI Client available. WordPress 7.0 and later bundle it, so there is nothing to do.
   On WordPress 6.9, install and activate the PHP AI Client plugin first.
2. Activate this plugin.
3. Register an application in Microsoft Entra ID, add the redirect URI shown on the settings screen, grant the seven
   delegated Microsoft Graph permissions listed there, and create a client secret.
4. Enter the tenant ID, client ID and client secret under Settings > Microsoft Copilot AI.
5. Each user clicks "Connect Microsoft account".

== Changelog ==

= 0.2.1 =
* Corrected the WordPress version guidance: the plugin supports 6.9, where the PHP AI Client is a
  separate plugin, as well as 7.0 and later, which bundle it. An admin notice previously implied
  7.0 was required.
* Documented the request timeouts, the token storage model, and that `customOptions` can override
  the web grounding setting.
* Stated plainly that the plugin has not yet been run against a live Microsoft tenant.

= 0.2.0 =
* Requests to Microsoft Graph now carry explicit timeouts, so a slow Copilot turn cannot occupy a
  PHP worker indefinitely.
* A failed token refresh no longer disconnects the account unless Microsoft reports the grant as
  genuinely dead, and concurrent refreshes no longer race each other into a disconnect.
* The OAuth return trip now works when the WordPress session expired during Microsoft sign-in.
* Tokens are encrypted with libsodium authenticated encryption. Storage is refused rather than
  written in plaintext when encryption is unavailable.
* The client secret is no longer kept in the autoloaded options cache.
* Added an uninstall routine that removes the settings and all stored tokens.
* Added a Privacy section.

= 0.1.0 =
* Initial release.
