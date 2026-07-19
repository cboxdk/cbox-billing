<?php

declare(strict_types=1);

namespace App\Billing\TestMode;

use Psr\Log\LoggerInterface;

/**
 * The sink test-mode notifications land in instead of a real inbox. A test-mode billing
 * event is recorded here (and logged) rather than queued to the mailer, so the sandbox never
 * delivers mail to a real customer. Held per request; the count/list is what the console can
 * surface as "captured, not sent".
 */
class CapturedNotifications
{
    /** @var list<array{event: string, subject: string, recipient: string}> */
    private array $captured = [];

    public function __construct(private readonly LoggerInterface $log) {}

    public function capture(string $event, string $subject, string $recipient): void
    {
        $this->captured[] = ['event' => $event, 'subject' => $subject, 'recipient' => $recipient];

        $this->log->info('Captured test-mode billing notification (not delivered).', [
            'event' => $event,
            'subject' => $subject,
            'recipient' => $recipient,
        ]);
    }

    /** @return list<array{event: string, subject: string, recipient: string}> */
    public function all(): array
    {
        return $this->captured;
    }

    public function count(): int
    {
        return count($this->captured);
    }
}
