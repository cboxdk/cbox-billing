<?php

declare(strict_types=1);

/*
 * Shared chrome for the branded transactional-email layout (the header/footer text that wraps
 * every template body). The per-event copy lives in resources/mail-templates/{locale}.php;
 * this is only the layout scaffolding, so a new locale is a drop-in: add lang/{locale}/emails.php.
 */

return [
    'automated' => 'This is an automated message from :product regarding your account.',
    'support_prompt' => 'Need help?',
    'contact_support' => 'Contact support',
    'view_in_browser' => 'View in browser',
];
