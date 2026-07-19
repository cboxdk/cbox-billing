<?php

declare(strict_types=1);

namespace App\Mail;

use App\Billing\Notifications\Contracts\ComposesTransactionalMail;
use App\Billing\Notifications\MailEventType;
use App\Billing\Notifications\Rendering\RenderedMail;
use App\Billing\Support\MoneyFormatter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The shared base for every lifecycle email. A concrete subclass carries the event's typed
 * payload (so the trigger points and tests keep their strongly-typed constructors) and maps
 * it to the template variable bag via {@see eventType()} + {@see variables()}. ALL rendering —
 * template resolution, per-seller branding, localization, the sandboxed render, the branded
 * layout and the plain-text alternative — is delegated to the {@see ComposesTransactionalMail}
 * pipeline. There is no hard-coded Blade body: the subclass is a thin, queued carrier over the
 * one render seam, so what ships is always the operator-editable, branded, localized template.
 *
 * The notifier stamps the resolved issuing seller + customer locale via {@see brand()} before
 * queueing; both default safely so a directly-constructed instance still renders.
 */
abstract class TransactionalMailable extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** The resolved issuing selling entity, or null to brand with the default seller. */
    public ?string $sellerEntityId = null;

    /**
     * The resolved customer locale; defaults to the app fallback until stamped. Named
     * `$mailLocale` to avoid shadowing Mailable's own untyped `$locale` render-locale property.
     */
    public string $mailLocale = 'en';

    private ?RenderedMail $rendered = null;

    /** The event type this mail renders — the template + branding key. */
    abstract public function eventType(): MailEventType;

    /**
     * The template variable bag, built from the subclass's typed payload. Values are already
     * locale-formatted by the notifier (amounts via {@see MoneyFormatter},
     * dates via Carbon), so the renderer only interpolates them.
     *
     * @return array<string, mixed>
     */
    abstract public function variables(): array;

    /** Stamp the resolved issuing seller + customer locale (called by the notifier). */
    public function brand(?string $sellerEntityId, string $locale): static
    {
        $this->sellerEntityId = $sellerEntityId;
        $this->mailLocale = $locale;

        return $this;
    }

    public function envelope(): Envelope
    {
        $mail = $this->compose();

        $from = $mail->fromEmail !== '' ? new Address($mail->fromEmail, $mail->fromName) : null;
        $replyTo = $mail->replyTo !== null && $mail->replyTo !== '' ? [new Address($mail->replyTo)] : [];

        return new Envelope(from: $from, replyTo: $replyTo, subject: $mail->subject);
    }

    public function content(): Content
    {
        $mail = $this->compose();

        return new Content(
            view: 'emails.rendered',
            text: 'emails.rendered-text',
            with: ['html' => $mail->html, 'text' => $mail->text],
        );
    }

    /** Compose once per instance — envelope() and content() share the single render. */
    protected function compose(): RenderedMail
    {
        return $this->rendered ??= app(ComposesTransactionalMail::class)
            ->compose($this->eventType(), $this->variables(), $this->sellerEntityId, $this->mailLocale);
    }
}
