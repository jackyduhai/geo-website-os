<?php

namespace App\Services\Sync;

use RuntimeException;

/**
 * GEO OS V1.4 · concurrent duplicate delivery signal.
 *
 * Raised when the UNIQUE(site_id, external_id) constraint rejects an INSERT
 * inside the upsert transaction. The transaction is then rolled back and the
 * conflict is resolved in a FRESH transaction.
 *
 * Why a separate transaction is mandatory:
 *
 *   PostgreSQL aborts the whole transaction as soon as a statement fails.
 *   After a unique violation (SQLSTATE 23505) every further statement in that
 *   transaction fails with 25P02 "current transaction is aborted". Reloading
 *   the winning row inside the SAME transaction therefore cannot work on
 *   PostgreSQL at all — it works on SQLite and MySQL only because those
 *   engines keep the transaction usable after a constraint violation, which
 *   hides the defect until the code runs on PostgreSQL.
 *
 *   Rolling back and opening a new transaction is correct on every driver:
 *   the new transaction gets a fresh snapshot (PostgreSQL READ COMMITTED) and
 *   can observe the row that the winner committed.
 */
class UniqueContentConflict extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $attributes  the attributes that were about to be inserted
     */
    public function __construct(
        public readonly array $attributes,
        public readonly string $externalId,
    ) {
        parent::__construct("Concurrent duplicate delivery for external_id [{$externalId}]");
    }
}
