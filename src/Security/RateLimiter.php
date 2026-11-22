<?php

declare(strict_types=1);

namespace Voxora\Security;

use Predis\Client as RedisClient;
use Predis\Connection\ConnectionException;
use Exception;

/**
 * RateLimiter
 *
 * Redis-backed fixed window rate limiter with per-identifier tracking.
 * Fails open on Redis errors to maintain availability.
 * Used by ApiController to enforce limits on API and dashboard actions.
 */
class RateLimiter
{
    private RedisClient $redis;
    private int $limit;
    private int $window;

    public function __construct(RedisClient $redis, array $config)
    {
        $this->redis = $redis;
        $this->limit = (int) ($config['rate_limit']['requests'] ?? 100);
        $this->window = (int) ($config['rate_limit']['window'] ?? 60);
    }

    public function isAllowed(string $identifier): bool
    {
        $key = "rate:{$identifier}";

        try {
            $count = (int) $this->redis->incrby($key, 1);

            if ($count === 1) {
                $this->redis->expire($key, $this->window);
            }

            if ($count > $this->limit) {
                throw new RateLimitExceededException(
                    'Rate limit exceeded. Try again later.',
                    $this->limit,
                    0,
                    $this->window
                );
            }

            return true;
        } catch (ConnectionException | Exception $e) {
            if ($e instanceof RateLimitExceededException) {
                throw $e;
            }
            // fail open on Redis outage
            return true;
        }
    }

    public function getRemaining(string $identifier): int
    {
        $key = "rate:{$identifier}";

        try {
            $count = (int) $this->redis->get($key);
            return max(0, $this->limit - $count);
        } catch (ConnectionException | Exception $e) {
            return $this->limit;
        }
    }
}

/**
 * Exception thrown when rate limit is exceeded.
 */
class RateLimitExceededException extends Exception
{
    public function __construct(
        string $message,
        public readonly int $limit,
        public readonly int $remaining,
        public readonly int $windowSeconds
    ) {
        parent::__construct($message, 429);
    }
}
