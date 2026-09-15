# AI Provider for Microsoft Copilot

Microsoft 365 Copilot provider for the [WordPress PHP AI Client](https://github.com/WordPress/php-ai-client).

```php
$result = AiClient::prompt('Summarise our Q3 planning notes.')
    ->usingProvider('microsoft-copilot')
    ->generateTextResult();
```

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

**One pseudo-model.** Graph publishes no model catalogue for Copilot and callers cannot choose the underlying model, so
`CopilotModelMetadataDirectory` returns a single fixed `microsoft-365-copilot` entry instead of issuing a list-models
request.

## Advertised options

Only options the endpoint honours are declared, so the AI Client routes prompts needing anything else to a different
provider rather than sending a request that is silently ignored:

| Option | Status |
|---|---|
| `systemInstruction` | Supported, delivered as grounding context |
| `webSearch` | Supported, maps to `contextualResources.webContext` |
| `customOptions` | Supported, passes `contextualResources` through verbatim |
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

## Caveats worth repeating

The Chat API is on Graph `/beta`, which Microsoft marks as unsupported for production and subject to change. Every user
needs a Microsoft 365 Copilot add-on license. Background generation without a signed-in user is not possible.

## License

GPL-2.0-or-later.
