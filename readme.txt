=== AI Provider for Microsoft Copilot ===
Contributors: alansmodic
Tags: ai, microsoft, copilot, microsoft-365
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://spdx.org/licenses/GPL-2.0-or-later.html

Microsoft 365 Copilot provider for the WordPress AI Client. Each user connects their own Microsoft work account.

== Description ==

Registers Microsoft 365 Copilot as a provider for the WordPress AI Client, so any plugin built on the AI Client can
generate text through Copilot.

Answers are grounded in the connected person's own Microsoft 365 content, and Microsoft's permission model is preserved:
Copilot can only reach files, mail and messages that the signed-in user could already open themselves.

= Requirements =

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

== Installation ==

1. Install and activate the PHP AI Client (bundled with WordPress 7.0+).
2. Activate this plugin.
3. Register an application in Microsoft Entra ID, add the redirect URI shown on the settings screen, grant the seven
   delegated Microsoft Graph permissions listed there, and create a client secret.
4. Enter the tenant ID, client ID and client secret under Settings > Microsoft Copilot AI.
5. Each user clicks "Connect Microsoft account".

== Changelog ==

= 0.1.0 =
* Initial release.
