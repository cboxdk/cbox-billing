<?php

declare(strict_types=1);

namespace App\Webhooks\Exceptions;

use RuntimeException;

/**
 * Thrown when an outbound webhook URL fails the SSRF guard — at registration (refuse the endpoint)
 * or immediately before delivery (a DNS rebind now resolves to a blocked address). Carries the
 * guard's reason so the console can show why an endpoint was refused.
 */
class UnsafeWebhookUrl extends RuntimeException
{
    public static function make(string $reason): self
    {
        return new self($reason);
    }
}
