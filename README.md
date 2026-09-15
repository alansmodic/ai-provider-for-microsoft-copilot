# AI Provider for Microsoft Copilot

Microsoft 365 Copilot provider for the [WordPress PHP AI Client](https://github.com/WordPress/php-ai-client).

```php
$result = AiClient::prompt('Summarise our Q3 planning notes.')
    ->usingProvider('microsoft-copilot')
    ->generateTextResult();
```

## Requirements

| | |
|---|---|
| WordPress | 6.9 or later |
| PHP | 7.4 or later, with the sodium extension |
| PHP AI Client | Bundled with WordPress 7.0 and later; a separate plugin on 6.9 |
| Microsoft | An Entra ID app registration, and a Microsoft 365 Copilot add-on license per user |

There is deliberately no `Requires Plugins` header. On WordPress 7.0 and later the AI Client is part of core rather
than a plugin, so declaring the dependency that way would block activation on exactly the versions that satisfy it.
The plugin checks for `AiClient` at runtime instead and shows an admin notice when it is missing.

## How it differs from the other AI Client providers

Anthropic, OpenAI and Gemini providers take one site-wide API key and talk to a completions endpoint. The Microsoft 365
Copilot Chat API does neither, and this provider is shaped around those two facts.

**Delegated authentication only.** Microsoft's permissions reference lists *Application* and *Delegated (personal
Microsoft account)* as "Not supported" for the Chat API. Only *Delegated (work or school account)* works. There is no
key the site can hold on its own behalf, so this plugin ships an Entra ID OAuth flow and stores a refresh token per
WordPress user. `CopilotProviderAvailability::isConfigured()` is therefore per-user: it is false for a visitor, false
for an admin who has not signed in to Microsoft, and false inside cron.

The PHP AI Client models API key authentication only — `RequestAuthenticationMethod` has a single `API_KEY` case — so
the provider overrides `getRequestAuthentication()` on both the model and the metadata directory and substitutes
`CopilotBearerRequestAuthentication`. The declared authentication method in `ProviderMetadata` is nominal; no API key
field is ever read.

**A conversation API, not a completions API.** Copilot keeps conversation state server side. Generating text means
`POST /copilot/conversations` to open a conversation, then `POST /copilot/conversations/{id}/chat` to send one turn.
`CopilotTextGenerationModel` opens a fresh conversation per call and replays any prior prompt messages, plus the system
instruction, as `additionalContext` — the documented channel for extra grounding text. Copilot treats that history as
background material rather than as turns of its own, which is the closest available mapping.

**Timeouts are set here, not by the SDK.** The AI Client resolves its HTTP client through `Psr18ClientDiscovery` and
applies no timeout of its own, and Microsoft documents the Chat API as prone to gateway timeouts. Without a limit a slow
turn occupies a PHP worker until the web server kills it, so the model sets its own: 60 seconds for a chat turn, 15 for
opening a conversation, 10 to connect. A caller that supplies its own `RequestOptions` is left alone, including when it
deliberately sets none.

**One pseudo-model.** Graph publishes no model catalogue for Copilot and callers cannot choose the underlying model, so
`CopilotModelMetadataDirectory` returns a single fixed `microsoft-365-copilot` entry instead of issuing a list-models
request.

## Advertised options

Only options the endpoint honours are declared, so the AI Client routes prompts needing anything else to a different
provider rather than sending a request that is silently ignored:

| Option | Status |
|---|---|
| `systemInstruction` | Supported, delivered as grounding context |
| `webSearch` | Supported. Web grounding is switched **off** unless a prompt asks for it, and the toggle is sent every turn because Microsoft treats it as single-turn |
| `customOptions` | Supported. A `contextualResources` key is forwarded to Graph verbatim, which means it can also override the web grounding decision above — treat it as a privileged passthrough |
| `temperature`, `topP`, `topK` | Not supported by the API |
| `maxTokens`, `stopSequences` | Not supported by the API |
| `functionDeclarations` | Not supported by the API |
| `outputSchema`, `outputMimeType` | Not supported by the API |

Token usage is reported as zero because the Chat API returns no token counts. Citations, when present, are passed
through on the result's additional data as `attributions`.

## Configuration

Settings live under **Settings → Microsoft Copilot AI**. On production sites, prefer defining the credentials as
constants so the secret stays out of the database:

```php
define('MICROSOFT_COPILOT_TENANT_ID', '...');
define('MICROSOFT_COPILOT_CLIENT_ID', '...');
define('MICROSOFT_COPILOT_CLIENT_SECRET', '...');
```

The Entra ID app registration needs the redirect URI shown on the settings screen and these seven delegated Microsoft
Graph permissions, all of which Microsoft requires for a single Chat API call to succeed: `Sites.Read.All`,
`Mail.Read`, `People.Read.All`, `OnlineMeetingTranscript.Read.All`, `Chat.Read`, `ChannelMessage.Read.All`,
`ExternalItem.Read.All` — plus `offline_access` to receive a refresh token.

## Development

```bash
composer install
composer qa          # php -l, PHPCS and PHPStan
composer phpcbf      # auto-fix what PHPCS can
```

PHPStan runs at **level 8** with `szepeviktor/phpstan-wordpress` for the WordPress stubs. PHPCS enforces PSR-12
alongside the WordPress security and i18n rules — PSR-12 rather than the full WordPress standard because these provider
packages are PSR-4 Composer libraries, matching how `wordpress/ai-provider-for-anthropic` is built. Two rules are
excluded deliberately, each documented in `phpcs.xml.dist`: PSR-1's side-effects rule for `plugin.php`, which a plugin
bootstrap cannot satisfy, and `EscapeOutput.ExceptionNotEscaped`, which misreads library exception messages as screen
output.

CI runs all three on every push and pull request, with `php -l` additionally on PHP 7.4 so syntax newer than the
declared floor cannot pass unnoticed.

## Storage and privacy

Each connected user's tokens live in user meta, encrypted with `sodium_crypto_secretbox` keyed from `wp_salt('auth')`.
If libsodium or the salt is unavailable the plugin refuses to store anything rather than falling back to plaintext — a
refresh token here carries `Mail.Read`, `Sites.Read.All` and `Chat.Read` against the owner's account.

The client secret is stored with autoloading disabled, so it is not read into memory on every page load. Better still,
define it as a constant and keep it out of the database entirely.

`uninstall.php` removes the settings and every user's tokens. It cannot revoke anything at Microsoft; consent is
withdrawn at [myaccount.microsoft.com](https://myaccount.microsoft.com/).

Note for multisite: user meta is network-global while the app registration is a per-site option, so a user connected on
one site of a network is connected on all of them, even where a different Entra ID application is configured. Treat the
network as one trust boundary.

## Caveats worth repeating

The Chat API is on Graph `/beta`, which Microsoft marks as unsupported for production and subject to change. Every user
needs a Microsoft 365 Copilot add-on license. Background generation without a signed-in user is not possible.

**This plugin has not been run against a live tenant.** It passes `php -l`, PHPStan level 8 and PHPCS, and static
analysis has already caught one bug that would have made every request fail, but no part of the OAuth flow, the token
refresh or the Graph calls has been exercised for real. There is no test suite yet either. Treat the runtime behaviour
as unverified until you have completed a connection yourself.

## License

GPL-2.0-or-later.
