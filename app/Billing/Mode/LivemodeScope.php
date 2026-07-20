<?php

declare(strict_types=1);

namespace App\Billing\Mode;

/**
 * BC alias of {@see EnvironmentScope}. The plane partition moved from the binary `livemode`
 * boolean to the first-class `environment` key; this thin subclass keeps the old type name valid
 * for any code (or migration docblock) that still references it, while the actual filtering is
 * done, keyed by environment, in the parent. New code should reference {@see EnvironmentScope}.
 */
class LivemodeScope extends EnvironmentScope {}
