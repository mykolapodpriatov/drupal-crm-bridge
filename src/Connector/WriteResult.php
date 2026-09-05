<?php

declare(strict_types=1);

namespace Drupal\crm_bridge\Connector;

/**
 * What an upsert produced.
 */
final class WriteResult {

  /**
   * Constructs the result.
   *
   * @param string $remoteId
   *   The peer's identifier for the record.
   * @param bool $created
   *   TRUE for an insert, FALSE for an update. A replayed idempotency key must
   *   report the original outcome, so that a retry does not make the caller
   *   believe it updated something it actually inserted.
   * @param int $updatedAt
   *   The peer's modification timestamp after the write.
   * @param string $version
   *   The peer's version token after the write.
   */
  public function __construct(
    public readonly string $remoteId,
    public readonly bool $created = FALSE,
    public readonly int $updatedAt = 0,
    public readonly string $version = '',
  ) {}

}
