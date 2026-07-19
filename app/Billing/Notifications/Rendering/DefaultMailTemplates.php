<?php

declare(strict_types=1);

namespace App\Billing\Notifications\Rendering;

use App\Billing\Notifications\MailEventType;

/**
 * The templates shipped in code — the never-dead-end floor of the resolution chain. Each
 * supported locale has a file (resources/mail-templates/{locale}.php) returning
 * [event_type => ['subject' => …, 'body' => …]] authored in the restricted mustache syntax.
 * A DB row overrides these; absent one, this renders. Files are cached per request so a
 * render pass reads each locale once.
 */
class DefaultMailTemplates
{
    /** @var array<string, array<string, array{subject: string, body: string}>> */
    private array $cache = [];

    public function __construct(private readonly string $basePath) {}

    /**
     * The shipped default for an event in a locale, or null if that locale ships none (the
     * resolver then tries the fallback locale).
     *
     * @return array{subject: string, body: string}|null
     */
    public function get(MailEventType $event, string $locale): ?array
    {
        return $this->load($locale)[$event->value] ?? null;
    }

    /**
     * @return array<string, array{subject: string, body: string}>
     */
    private function load(string $locale): array
    {
        $locale = strtolower($locale);

        if (array_key_exists($locale, $this->cache)) {
            return $this->cache[$locale];
        }

        $file = $this->basePath.'/'.$locale.'.php';
        $loaded = is_file($file) ? require $file : [];

        $templates = [];

        if (is_array($loaded)) {
            foreach ($loaded as $event => $definition) {
                if (! is_array($definition)) {
                    continue;
                }

                $subject = $definition['subject'] ?? null;
                $body = $definition['body'] ?? null;

                if (is_string($subject) && is_string($body)) {
                    $templates[(string) $event] = ['subject' => $subject, 'body' => $body];
                }
            }
        }

        return $this->cache[$locale] = $templates;
    }
}
