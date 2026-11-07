<?php

declare(strict_types=1);

// Existing SSR preview remains isolated from real payment records and provider operations.
return ['provider' => 'paystack', 'preview_provider' => 'mock'];
