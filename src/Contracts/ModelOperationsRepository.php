<?php

declare(strict_types=1);

namespace App\Contracts;

interface ModelOperationsRepository
{
    /**
     * Run under one DB transaction and a registry-wide lock (including wildcard
     * scopes). Invoke $work with ModelOperationsStore. Commit version/assignment
     * projections, immutable health/audit events, command result and outbox
     * atomically. Roll back ALL writes on exception. Fail if unavailable.
     */
    public function transaction(callable $work): mixed;
}
