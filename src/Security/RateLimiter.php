<?php

declare(strict_types=1);

namespace Voxora\Security;

use Predis\Client as PredisClient;
use Predis\ClientInterface;
use RuntimeException;

/**
 * RateLimiter
 *
 * Redis-backed fixed-window rate limiter for request throttling.
 * Uses per-window increment counters (e.g. rate:user:123:174000). 
 * Identifier can be a user ID, token hash or IP address.
 * Fails open (returns true) on Redis errors to avoid blocking production.
 */
final class RateLimiter
{
    private ClientInterface $client;
    private int $maxRequests;
    private int $windowSeconds;

    /**
     * @param ClientInterface $client Predis client connected to Redis
     * @param int $maxRequests Maximum requests allowed inside the window
     * @param int $windowSeconds Duration of the sliding window in seconds
     */
    public function __construct(
        ClientInterface $client,
        int $maxRequests = 100,
        int $windowSeconds = 60
    ) {
        $this->client = $client;
        $this->maxRequests = $maxRequests;
        $this->windowSeconds = $windowSeconds;
    }

    /**
     * Determine whether a request is allowed for the given identifier.
     */
    public function isAllowed(string $identifier): bool
    {
        $window = (int) (time() / $this->windowSeconds);
        $key = "ratelimit:{$identifier}:{$window}";

        try {
            $count = (int) $this->client->incr($key);

            if ($count === 1) {
                $this->client->expire($key, $this->windowSeconds);
            }

            return $count <= $this->maxRequests;
        } catch (\Throwable) {
            return true; // fail open
        }
    }

    /**
     * Return how many requests remain in the current window.
     */
    public function getRemaining(string $identifier): int
    {
        $window = (int) (time() / $this->windowSeconds);
        $key = "ratelimit:{$identifier}:{$window}";

        try {
            $used = (int) $this->client->get($key);
            return max(0, $this->maxRequests - $used);
        } catch (\Throwable) {
            return $this->maxRequests;
        }
    }

    public function getLimit(): int
    {
        return $this->maxRequests;
    }

    public function getWindow(): int
    {
        return $this->windowSeconds;
    }
}
