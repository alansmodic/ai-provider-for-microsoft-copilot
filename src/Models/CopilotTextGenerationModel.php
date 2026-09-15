<?php

declare(strict_types=1);

namespace WordPress\MicrosoftCopilotAiProvider\Models;

use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;
use WordPress\MicrosoftCopilotAiProvider\Authentication\CopilotBearerRequestAuthentication;
use WordPress\MicrosoftCopilotAiProvider\Provider\CopilotProvider;

/**
 * Text generation model backed by the Microsoft 365 Copilot Chat API.
 *
 * The Chat API is not a completions endpoint. It keeps conversation state server side and exposes
 * two calls: one to open a conversation and one to send a turn into it. This class maps the AI
 * Client's stateless "here is the whole prompt" contract onto that shape by opening a fresh
 * conversation per generation and replaying any prior turns as grounding context.
 *
 * @since 0.1.0
 *
 * @phpstan-type ChatResponseData array{
 *     id?: string,
 *     messages?: list<array{text?: string, attributions?: list<array<string, mixed>>}>
 * }
 */
class CopilotTextGenerationModel extends AbstractApiBasedModel implements TextGenerationModelInterface
{
    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    public function getRequestAuthentication(): RequestAuthenticationInterface
    {
        return new CopilotBearerRequestAuthentication();
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     *
     * @param list<Message> $prompt The prompt messages.
     * @return GenerativeAiResult The generation result.
     */
    final public function generateTextResult(array $prompt): GenerativeAiResult
    {
        $conversationId = $this->createConversation();
        $response = $this->sendChatTurn($conversationId, $prompt);

        return $this->parseResponseToGenerativeAiResult($response, $conversationId);
    }

    /**
     * Opens a new Copilot conversation and returns its ID.
     *
     * @since 0.1.0
     *
     * @return string The conversation ID.
     * @throws ResponseException If the conversation cannot be created.
     */
    protected function createConversation(): string
    {
        $request = new Request(
            HttpMethodEnum::POST(),
            CopilotProvider::url('copilot/conversations'),
            ['Content-Type' => 'application/json'],
            /*
             * The endpoint expects an empty JSON *object*. The body is passed as a raw string
             * because an empty PHP array would be encoded as `[]`, which Graph rejects.
             */
            '{}',
            $this->getRequestOptions()
        );

        $request = $this->getRequestAuthentication()->authenticateRequest($request);
        $response = $this->getHttpTransporter()->send($request);

        ResponseUtil::throwIfNotSuccessful($response);

        $data = $response->getData();

        if (!is_array($data) || !isset($data['id']) || !is_string($data['id'])) {
            throw ResponseException::fromMissingData('Microsoft 365 Copilot', 'id');
        }

        return $data['id'];
    }

    /**
     * Sends the prompt into an open conversation.
     *
     * @since 0.1.0
     *
     * @param string $conversationId The conversation ID.
     * @param list<Message> $prompt The prompt messages.
     * @return Response The HTTP response.
     * @throws ResponseException If the request fails.
     */
    protected function sendChatTurn(string $conversationId, array $prompt): Response
    {
        $request = new Request(
            HttpMethodEnum::POST(),
            CopilotProvider::url(
                sprintf('copilot/conversations/%s/chat', rawurlencode($conversationId))
            ),
            ['Content-Type' => 'application/json'],
            $this->prepareChatParams($prompt),
            $this->getRequestOptions()
        );

        $request = $this->getRequestAuthentication()->authenticateRequest($request);
        $response = $this->getHttpTransporter()->send($request);

        ResponseUtil::throwIfNotSuccessful($response);

        return $response;
    }

    /**
     * Builds the chat request body from the prompt and model configuration.
     *
     * @since 0.1.0
     *
     * @param list<Message> $prompt The prompt messages.
     * @return array<string, mixed> The request body.
     * @throws RuntimeException If the prompt contains no usable user message.
     */
    protected function prepareChatParams(array $prompt): array
    {
        if ($prompt === []) {
            throw new RuntimeException('The prompt must contain at least one message.');
        }

        $config = $this->getConfig();

        /*
         * Copilot accepts exactly one message per turn and has no notion of a system role. The
         * final user message becomes the turn; everything before it, plus any system instruction,
         * is handed over as `additionalContext`, which is the documented channel for extra
         * grounding text. This preserves the substance of a multi-turn prompt even though Copilot
         * will treat the history as background material rather than as prior turns of its own.
         */
        $history = $prompt;
        $latest = array_pop($history);

        if (!$latest instanceof Message) {
            throw new RuntimeException('The prompt must contain at least one message.');
        }

        $params = [
            'message' => ['text' => $this->flattenMessage($latest)],
            // locationHint is a required parameter; the site's timezone is the best available answer.
            'locationHint' => ['timeZone' => $this->getTimeZone()],
        ];

        $context = [];

        $systemInstruction = $config->getSystemInstruction();

        if ($systemInstruction !== null && $systemInstruction !== '') {
            $context[] = ['text' => $systemInstruction];
        }

        foreach ($history as $message) {
            $text = $this->flattenMessage($message);

            if ($text === '') {
                continue;
            }

            $context[] = [
                'text' => sprintf(
                    '%s: %s',
                    $message->getRole()->isModel() ? 'Assistant' : 'User',
                    $text
                ),
            ];
        }

        if ($context !== []) {
            $params['additionalContext'] = $context;
        }

        /*
         * Web grounding is on by default and, per Microsoft's reference, toggling it off is a
         * single-turn action, so the flag is sent on every request rather than once per session.
         */
        if ($config->getWebSearch() === null) {
            $params['contextualResources'] = ['webContext' => ['isWebEnabled' => false]];
        }

        $customOptions = $config->getCustomOptions();

        if (isset($customOptions['contextualResources']) && is_array($customOptions['contextualResources'])) {
            $params['contextualResources'] = $customOptions['contextualResources'];
        }

        return $params;
    }

    /**
     * Reduces a message to the plain text Copilot can accept.
     *
     * @since 0.1.0
     *
     * @param Message $message The message.
     * @return string The concatenated text of the message's text parts.
     */
    protected function flattenMessage(Message $message): string
    {
        $segments = [];

        foreach ($message->getParts() as $part) {
            $text = $this->getPartText($part);

            if ($text !== '') {
                $segments[] = $text;
            }
        }

        return trim(implode("\n\n", $segments));
    }

    /**
     * Extracts text from a single message part.
     *
     * Non-text parts are dropped: the model metadata advertises text-only input, so files and
     * function calls should never reach this point, but a prompt assembled by hand might contain
     * them and silently ignoring them is preferable to a fatal error.
     *
     * @since 0.1.0
     *
     * @param MessagePart $part The message part.
     * @return string The text, or an empty string for non-text parts.
     */
    protected function getPartText(MessagePart $part): string
    {
        if (!$part->getType()->isText()) {
            return '';
        }

        return (string) $part->getText();
    }

    /**
     * Gets the timezone to send as the location hint.
     *
     * @since 0.1.0
     *
     * @return string An IANA timezone identifier.
     */
    protected function getTimeZone(): string
    {
        if (function_exists('wp_timezone_string')) {
            $timeZone = wp_timezone_string();

            /*
             * Sites configured with a raw UTC offset yield strings like "+02:00", which Microsoft
             * rejects. Only genuine IANA identifiers are forwarded.
             */
            if (strpos($timeZone, '/') !== false) {
                return $timeZone;
            }
        }

        return 'UTC';
    }

    /**
     * Converts a chat response into an AI Client result.
     *
     * @since 0.1.0
     *
     * @param Response $response The HTTP response.
     * @param string $conversationId The conversation ID, used as the result ID.
     * @return GenerativeAiResult The result.
     * @throws ResponseException If the response contains no assistant message.
     */
    protected function parseResponseToGenerativeAiResult(
        Response $response,
        string $conversationId
    ): GenerativeAiResult {
        /** @var ChatResponseData|null $data */
        $data = $response->getData();

        if (!is_array($data) || !isset($data['messages']) || !is_array($data['messages'])) {
            throw ResponseException::fromMissingData('Microsoft 365 Copilot', 'messages');
        }

        /*
         * The response echoes the submitted turn and then appends Copilot's reply, so the answer
         * is the last message rather than the first.
         */
        $messages = array_values($data['messages']);
        $reply = end($messages);

        if (!is_array($reply) || !isset($reply['text']) || !is_string($reply['text'])) {
            throw ResponseException::fromMissingData('Microsoft 365 Copilot', 'messages.text');
        }

        $candidate = new Candidate(
            new Message(
                MessageRoleEnum::model(),
                [new MessagePart($this->cleanResponseText($reply['text']))]
            ),
            FinishReasonEnum::stop()
        );

        /*
         * The Chat API reports no token counts. Zeroes are recorded rather than guesses so that
         * downstream cost accounting is not silently fed fabricated numbers.
         */
        $tokenUsage = new TokenUsage(0, 0, 0);

        $additionalData = [];

        if (isset($reply['attributions']) && is_array($reply['attributions']) && $reply['attributions'] !== []) {
            $additionalData['attributions'] = $reply['attributions'];
        }

        return new GenerativeAiResult(
            $conversationId,
            [$candidate],
            $tokenUsage,
            $this->providerMetadata(),
            $this->metadata(),
            $additionalData
        );
    }

    /**
     * Strips Copilot's inline entity and citation markup from a reply.
     *
     * Copilot annotates answers with pseudo-tags such as `<Person>Jane Doe</Person>` and footnote
     * markers such as `[^1^]`. Those are meaningful inside Microsoft's own chat surfaces, which
     * render them as links, but they are noise when the text is destined for a post or an excerpt.
     * The surrounding text is kept; only the markers are removed.
     *
     * @since 0.1.0
     *
     * @param string $text The raw reply text.
     * @return string The cleaned text.
     */
    protected function cleanResponseText(string $text): string
    {
        // Unwrap entity tags, keeping their inner text.
        $text = (string) preg_replace(
            '#</?(?:Person|File|Event|Email|Meeting|Site|Team|Channel)>#i',
            '',
            $text
        );

        // Remove footnote citation markers such as [^1^] or [^12^].
        $text = (string) preg_replace('/\[\^\d+\^\]/', '', $text);

        return trim($text);
    }
}
