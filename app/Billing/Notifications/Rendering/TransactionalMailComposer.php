<?php

declare(strict_types=1);

namespace App\Billing\Notifications\Rendering;

use App\Billing\Notifications\Branding\BrandingResolver;
use App\Billing\Notifications\Branding\SellerBranding;
use App\Billing\Notifications\Contracts\ComposesTransactionalMail;
use App\Billing\Notifications\Contracts\RendersTemplates;
use App\Billing\Notifications\Contracts\ResolvesMailTemplates;
use App\Billing\Notifications\MailEventType;
use Illuminate\Contracts\View\Factory as ViewFactory;

/**
 * Composes a lifecycle email end to end: resolve the branding for the seller, resolve the
 * template from the chain in the requested locale, safe-render the subject + body against the
 * event's variable bag (plus the reserved branding variables), wrap the body in the branded
 * responsive layout, and derive the plain-text alternative. The output is a self-contained
 * {@see RenderedMail} — no model or config is read again downstream, so the queued mail and
 * the console preview are byte-identical.
 *
 * The render is sandboxed: the {@see RendersTemplates} implementation never evaluates PHP or
 * Blade over the stored template, and every interpolated value is HTML-escaped, so neither a
 * hostile template body nor a hostile variable value (a customer-controlled name, say) can
 * inject markup or execute code.
 */
readonly class TransactionalMailComposer implements ComposesTransactionalMail
{
    public function __construct(
        private ResolvesMailTemplates $templates,
        private RendersTemplates $renderer,
        private BrandingResolver $branding,
        private ViewFactory $views,
    ) {}

    public function compose(MailEventType $event, array $variables, ?string $sellerEntityId, string $locale): RenderedMail
    {
        $branding = $this->branding->forSeller($sellerEntityId);
        $resolved = $this->templates->resolve($event, $locale, $branding->sellerEntityId);

        return $this->render($branding, $resolved->subject, $resolved->body, $variables, $resolved->locale, $resolved->source);
    }

    public function composeDraft(MailEventType $event, string $subject, string $body, array $variables, ?string $sellerEntityId, string $locale): RenderedMail
    {
        $branding = $this->branding->forSeller($sellerEntityId);
        $normalized = $this->locale($locale);

        // A draft preview is, by definition, an unsaved seller/account override in this locale.
        return $this->render($branding, $subject, $body, $variables, $normalized, TemplateSource::GlobalLocale);
    }

    /**
     * The shared render tail both paths share: interpolate subject + body against the bag +
     * reserved branding variables, wrap in the branded layout, derive the plain-text part.
     *
     * @param  array<string, mixed>  $variables
     */
    private function render(SellerBranding $branding, string $subjectTemplate, string $bodyTemplate, array $variables, string $locale, TemplateSource $source): RenderedMail
    {
        $context = [...$variables, ...$this->reservedVariables($branding)];

        $subject = $this->collapseSubject($this->renderer->render($subjectTemplate, $context, false));
        $bodyHtml = $this->renderer->render($bodyTemplate, $context, true);

        $html = $this->views->make('emails.layout', [
            'branding' => $branding,
            'bodyHtml' => $bodyHtml,
            'subject' => $subject,
            'locale' => $locale,
        ])->render();

        return new RenderedMail(
            subject: $subject,
            html: $html,
            text: $this->toPlainText($bodyHtml, $branding, $locale),
            fromName: $branding->fromName,
            fromEmail: $branding->fromEmail,
            replyTo: $branding->replyTo,
            locale: $locale,
            source: $source,
        );
    }

    private function locale(string $locale): string
    {
        $normalized = strtolower(trim($locale));

        return $normalized === '' ? 'en' : $normalized;
    }

    /**
     * The reserved variables every template may reference in addition to its event bag — the
     * resolved branding, so a template (and the shipped defaults) can accent with the seller's
     * colour. Kept separate from the event bag; event variables never use these keys.
     *
     * @return array<string, string>
     */
    private function reservedVariables(SellerBranding $branding): array
    {
        return [
            'brand_color' => $branding->brandColor,
            'product_name' => $branding->productName,
            'support_url' => $branding->supportUrl ?? '',
            'support_email' => $branding->supportEmail ?? '',
        ];
    }

    /** A subject line is single-line plain text: strip any stray tags and collapse whitespace. */
    private function collapseSubject(string $subject): string
    {
        $stripped = strip_tags($subject);
        $collapsed = preg_replace('/\s+/', ' ', $stripped);

        return trim($collapsed ?? $stripped);
    }

    /**
     * Derive a readable plain-text alternative from the rendered HTML body: block elements
     * become line breaks, tags are stripped, entities decoded, runs of blank lines collapsed;
     * the legal footer line is appended so the text part still carries the issuing identity.
     */
    private function toPlainText(string $bodyHtml, SellerBranding $branding, string $locale): string
    {
        $withBreaks = preg_replace('/<(br|\/p|\/h1|\/h2|\/tr|\/li|\/div)[^>]*>/i', "\n", $bodyHtml) ?? $bodyHtml;
        $withBreaks = preg_replace('/<\/td>/i', "\t", $withBreaks) ?? $withBreaks;
        $text = html_entity_decode(strip_tags($withBreaks), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        $text = trim($text);

        $footer = $branding->legalLine();

        if ($branding->footerAddress !== null && trim($branding->footerAddress) !== '') {
            $footer .= "\n".trim($branding->footerAddress);
        }

        return $text."\n\n—\n".__('emails.automated', ['product' => $branding->productName], $locale)."\n".$footer;
    }
}
