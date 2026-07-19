<?php

declare(strict_types=1);

/*
 * Danske standardskabeloner for hver transaktionsmail-hændelse. Se en.php for formatet:
 * dette er bunden i opløsningskæden (en DB-række kan overskrive dem pr. hændelse/sprog/
 * sælger). Skrevet i den begrænsede, sandkasse-mustache-syntaks — ikke Blade — så intet her
 * evalueres som PHP. Brødteksten gengives inde i det brandede layout.
 */

$eyebrow = static fn (string $text): string => '<div style="font-size:12px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:{{ brand_color }};margin:0 0 10px;">'.$text.'</div>';
$heading = static fn (string $text): string => '<h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;letter-spacing:-.015em;color:#1a1714;">'.$text.'</h1>';
$p = static fn (string $text): string => '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;color:#44403a;">'.$text.'</p>';
$note = static fn (string $text): string => '<p style="margin:16px 0 0;font-size:13px;line-height:1.6;color:#6b6560;">'.$text.'</p>';
$row = static fn (string $label, string $value): string => '<tr><td style="padding:11px 16px;border-bottom:1px solid #efe9df;font-size:13px;color:#6b6560;">'.$label.'</td><td align="right" style="padding:11px 16px;border-bottom:1px solid #efe9df;font-size:14px;font-weight:600;color:#1a1714;">'.$value.'</td></tr>';
$total = static fn (string $label, string $value): string => '<tr><td style="padding:13px 16px;background:#faf7f2;font-size:13px;font-weight:600;color:#1a1714;">'.$label.'</td><td align="right" style="padding:13px 16px;background:#faf7f2;font-size:17px;font-weight:700;color:#1a1714;">'.$value.'</td></tr>';
$summaryOpen = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:8px 0 4px;border:1px solid #e5ded2;border-radius:10px;overflow:hidden;">';
$summaryClose = '</table>';

return [
    'invoice_issued' => [
        'subject' => 'Faktura {{ invoice_number }} — {{ amount_formatted }} forfalder {{ due_at_label }}',
        'body' => $eyebrow('Faktura udstedt').$heading('Faktura {{ invoice_number }}')
            .$p('Hej {{ organization_name }},')
            .$p('Der er udstedt en ny faktura for dit abonnement, som dækker {{ period_label }}.')
            .$summaryOpen
            .$row('Faktura', '{{ invoice_number }}')
            .$row('Faktureringsperiode', '{{ period_label }}')
            .$row('Udstedt', '{{ issued_at_label }}')
            .$row('Forfald', '{{ due_at_label }}')
            .$total('Beløb til betaling', '{{ amount_formatted }}')
            .$summaryClose
            .$note('Betalingsoplysninger fremgår af fakturaen. Har du allerede betalt, kan du se bort fra denne besked.'),
    ],

    'payment_receipt' => [
        'subject' => 'Betaling modtaget for faktura {{ invoice_number }}',
        'body' => $eyebrow('Kvittering').$heading('Betaling modtaget')
            .$p('Hej {{ organization_name }},')
            .$p('Tak — vi har modtaget din betaling, og faktura {{ invoice_number }} er nu betalt.')
            .$summaryOpen
            .$row('Faktura', '{{ invoice_number }}')
            .$row('Betalt den', '{{ paid_at_label }}')
            .'{{#if gateway_reference }}'.$row('Reference', '{{ gateway_reference }}').'{{/if}}'
            .$total('Betalt beløb', '{{ amount_formatted }}')
            .$summaryClose
            .$note('Denne kvittering bekræfter betalingen. Der kræves ingen yderligere handling.'),
    ],

    'payment_failed' => [
        'subject' => '{{#if suspended }}Din konto er blevet suspenderet{{else}}En betaling på din konto er forfalden{{/if}}',
        'body' => '{{#if suspended }}'.$eyebrow('Konto suspenderet').$heading('Din konto er blevet suspenderet').'{{else}}'.$eyebrow('Betalingspåmindelse').$heading('En betaling er forfalden').'{{/if}}'
            .$p('Hej {{ organization_name }},')
            .'{{#if suspended }}'.$p('Vi kunne ikke gennemføre betalingen på din konto, og adgangen er nu suspenderet. Betal venligst det udestående beløb fuldt ud for at genoprette adgangen.').'{{else}}'.$p('Vi har bemærket, at en betaling på din konto er forfalden. Betal venligst det udestående beløb for at undgå en afbrydelse af din service.').'{{/if}}'
            .$summaryOpen
            .'{{#if oldest_due_label }}'.$row('Ældste forfaldne', '{{ oldest_due_label }}').'{{/if}}'
            .$total('Udestående', '{{ amount_due_formatted }}')
            .$summaryClose
            .$note('Har du allerede betalt, kan du se bort fra denne besked — den kan have krydset din betaling.'),
    ],

    'payment_retry' => [
        'subject' => '{{#if exhausted }}Vi kunne ikke gennemføre din betaling for {{ invoice_number }}{{else}}Din betaling for {{ invoice_number }} gik ikke igennem{{/if}}',
        'body' => '{{#if exhausted }}'.$eyebrow('Betalingsproblem').$heading('Vi kunne ikke gennemføre din betaling')
            .$p('Hej {{ organization_name }},')
            .$p('Vi forsøgte {{ max_attempts }} gange at gennemføre betalingen for faktura {{ invoice_number }}, men det lykkedes ikke. For at undgå at miste adgang bedes du opdatere din betalingsmetode og betale det udestående beløb.')
            .$summaryOpen.$row('Faktura', '{{ invoice_number }}').$row('Status', 'Forsøg opbrugt').$total('Beløb til betaling', '{{ amount_formatted }}').$summaryClose
            .'{{else}}'.$eyebrow('Betalingsproblem').$heading('Din betaling gik ikke igennem')
            .$p('Hej {{ organization_name }},')
            .$p('Vi kunne ikke trække betalingen på din betalingsmetode for faktura {{ invoice_number }}. Din service fortsætter indtil videre — vi prøver automatisk igen{{#if next_attempt_label }} den {{ next_attempt_label }}{{/if}}.')
            .$summaryOpen.$row('Faktura', '{{ invoice_number }}').'{{#if next_attempt_label }}'.$row('Næste forsøg', '{{ next_attempt_label }}').'{{/if}}'.$total('Beløb til betaling', '{{ amount_formatted }}').$summaryClose
            .$note('Du kan undgå flere forsøg ved at opdatere din betalingsmetode i din betalingsportal.')
            .'{{/if}}',
    ],

    'trial_ending' => [
        'subject' => 'Din {{ plan_name }}-prøveperiode slutter den {{ ends_at_label }}',
        'body' => $eyebrow('Prøvepåmindelse').$heading('Din gratis prøveperiode slutter snart')
            .$p('Hej {{ organization_name }},')
            .$p('Din gratis prøveperiode af {{ plan_name }} slutter den {{ ends_at_label }}. Når den gør, starter dit abonnement automatisk, og vi trækker din første betaling — der kræves ingen handling for at fortsætte.')
            .$summaryOpen
            .$row('Plan', '{{ plan_name }}')
            .$row('Prøveperiode slutter', '{{ ends_at_label }}')
            .$total('Første betaling', '{{ amount_formatted }}')
            .$summaryClose
            .$note('Ikke klar til at fortsætte? Du kan opsige når som helst inden {{ ends_at_label }} fra din betalingsportal, og du bliver ikke opkrævet.'),
    ],

    'renewal_reminder' => [
        'subject' => 'Dit {{ plan_name }}-abonnement fornyes den {{ renews_at_label }}',
        'body' => $eyebrow('Fornyelsespåmindelse').$heading('Dit abonnement fornyes snart')
            .$p('Hej {{ organization_name }},')
            .$p('Dette er en påmindelse om, at dit {{ plan_name }}-abonnement er sat til at blive fornyet. Der kræves ingen handling — det fortsætter automatisk.')
            .$summaryOpen
            .$row('Plan', '{{ plan_name }}')
            .$row('Fornyes den', '{{ renews_at_label }}')
            .$total('Løbende beløb', '{{ amount_formatted }}')
            .$summaryClose
            .$note('Vil du ændre noget inden fornyelsen? Du kan opdatere eller opsige din plan fra din betalingsportal.'),
    ],

    'subscription_changed' => [
        'subject' => '{{#if is_canceled }}Dit abonnement er blevet opsagt{{/if}}{{#if is_cancel_scheduled }}Dit abonnement er planlagt til opsigelse{{/if}}{{#if is_plan_change }}Din plan er opdateret til {{ plan_name }}{{/if}}',
        'body' => '{{#if is_canceled }}'.$eyebrow('Opsigelse').$heading('Dit abonnement er blevet opsagt')
            .$p('Hej {{ organization_name }},')
            .$p('Dit {{ plan_name }}-abonnement er blevet opsagt og er nu lukket. Vi er kede af at se dig gå.')
            .$summaryOpen.$row('Plan', '{{ plan_name }}').$row('Status', 'Opsagt').$summaryClose
            .'{{/if}}{{#if is_cancel_scheduled }}'.$eyebrow('Opsigelse').$heading('Din opsigelse er planlagt')
            .$p('Hej {{ organization_name }},')
            .$p('Dit {{ plan_name }}-abonnement er planlagt til at blive opsagt ved udgangen af den nuværende periode. Indtil da fortsætter din service som normalt.')
            .$summaryOpen.$row('Plan', '{{ plan_name }}').'{{#if effective_at_label }}'.$row('Opsiges den', '{{ effective_at_label }}').'{{/if}}'.$summaryClose
            .'{{/if}}{{#if is_plan_change }}'.$eyebrow('Planændring').$heading('Din plan er opdateret')
            .$p('Hej {{ organization_name }},')
            .$p('Dit abonnement er ændret {{#if previous_plan_name }}fra {{ previous_plan_name }} {{/if}}til {{ plan_name }}.')
            .$summaryOpen.'{{#if previous_plan_name }}'.$row('Tidligere plan', '{{ previous_plan_name }}').'{{/if}}'.$row('Ny plan', '{{ plan_name }}').'{{#if effective_at_label }}'.$row('Gælder fra', '{{ effective_at_label }}').'{{/if}}'.$summaryClose
            .'{{/if}}'
            .$note('Du kan altid gennemgå dit abonnement fra din betalingsportal.'),
    ],

    'license_delivered' => [
        'subject' => '{{#if reissued }}Din fornyede Cbox-licensnøgle{{else}}Din Cbox-licensnøgle{{/if}}',
        'body' => '{{#if reissued }}'.$eyebrow('Licens fornyet').$heading('Din fornyede licensnøgle').'{{else}}'.$eyebrow('Licens udstedt').$heading('Din licensnøgle').'{{/if}}'
            .$p('Hej {{ organization_name }},')
            .$p('Din {{ plan_label }} on-prem-licens {{#if reissued }}er blevet fornyet{{else}}er klar{{/if}}. Kopiér nøglen nedenfor ind i miljøet på din selvhostede installation som <code style="font-family:\'SF Mono\',Menlo,monospace;font-size:13px;background:#f3ede4;padding:1px 5px;border-radius:4px;">CBOX_ID_LICENSE_KEY</code>.')
            .$summaryOpen
            .$row('Plan', '{{ plan_label }}')
            .$row('Installation', '{{ deployment_id }}')
            .$row('Gyldig til', '{{ expires_at_label }}')
            .$summaryClose
            .'<p style="margin:18px 0 6px;font-size:13px;font-weight:600;color:#1a1714;">CBOX_ID_LICENSE_KEY</p>'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td style="padding:14px 16px;background:#1a1714;border-radius:10px;font-family:\'SF Mono\',Menlo,Consolas,monospace;font-size:12px;line-height:1.5;color:#faf7f2;word-break:break-all;">{{ license_key }}</td></tr></table>'
            .$note('Denne nøgle er gyldig til {{ expires_at_label }}. Vi sender en ny nøgle, når din licens fornyes.'),
    ],

    'plan_retiring' => [
        'subject' => '{{ plan_name }} udgår den {{ retires_at_label }}',
        'body' => $eyebrow('Planopdatering').$heading('Din plan udgår')
            .$p('Hej {{ organization_name }},')
            .$p('{{ plan_name }} udgår den {{ retires_at_label }}. Dit abonnement fungerer indtil da — men ved din næste fornyelse den {{ renewal_due_label }} skal du skifte til en ny plan.')
            .$summaryOpen
            .$row('Plan der udgår', '{{ plan_name }}')
            .$row('Udgår den', '{{ retires_at_label }}')
            .$row('Vælg inden (næste fornyelse)', '{{ renewal_due_label }}')
            .'{{#if default_successor_name }}'.$row('Standardplan', '{{ default_successor_name }}').'{{/if}}'
            .$summaryClose
            .'{{#if default_successor_name }}'.$note('Hvis du ikke foretager dig noget, flytter vi dig til <strong>{{ default_successor_name }}</strong> ved din næste fornyelse. Du kan også vælge en anden plan eller opsige fra din betalingsportal inden da.').'{{else}}'.$note('Vælg venligst en ny plan fra din betalingsportal inden {{ renewal_due_label }} — dit abonnement kan ikke fornyes på den udgåede plan.').'{{/if}}',
    ],
];
