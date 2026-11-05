<?php

declare(strict_types=1);

// Live billing is intentionally unavailable until an authenticated provider exists.
return ['provider' => getenv('BILLING_PROVIDER') ?: 'mock'];
