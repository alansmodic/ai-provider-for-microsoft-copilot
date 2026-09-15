<?php

declare(strict_types=1);

namespace WordPress\MicrosoftCopilotAiProvider\Authentication;

use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\MicrosoftCopilotAiProvider\Auth\TokenProvider;

/**
 * Authenticates Graph requests with the signed-in user's delegated OAuth token.
 *
 * The PHP AI Client currently models API key authentication only, so this provider bypasses the
 * SDK's credential plumbing and resolves a bearer token per request instead. Resolving it at send
 * time rather than at construction time means a token that expires mid-session is renewed
 * transparently.
 *
 * @since 0.1.0
 */
class CopilotBearerRequestAuthentication implements RequestAuthenticationInterface
{
    /**
     * @var TokenProvider The token resolver.
     */
    private TokenProvider $tokenProvider;

    /**
     * @var int|null The user whose token should be used, or null for the current user.
     */
    private ?int $userId;

    /**
     * Constructor.
     *
     * @since 0.1.0
     *
     * @param TokenProvider|null $tokenProvider Optional token provider.
     * @param int|null $userId Optional explicit user ID.
     */
    public function __construct(?TokenProvider $tokenProvider = null, ?int $userId = null)
    {
        $this->tokenProvider = $tokenProvider ?? new TokenProvider();
        $this->userId = $userId;
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     *
     * @throws RuntimeException If the user has no connected Microsoft account.
     */
    public function authenticateRequest(Request $request): Request
    {
        $token = $this->tokenProvider->getAccessToken($this->userId);

        if ($token === null) {
            throw new RuntimeException(
                'No connected Microsoft account for the current user. ' .
                'Connect one under Settings > Microsoft Copilot AI before using this provider.'
            );
        }

        return $request->withHeader('Authorization', 'Bearer ' . $token);
    }
}
