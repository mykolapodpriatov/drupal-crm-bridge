<?php

declare(strict_types=1);

namespace Drupal\crm_bridge\Storage;

/**
 * One entry in the dead-letter or review queue.
 *
 * Both carry the same envelope, because both answer the same question for
 * whoever opens them: what was this, why is it here, and what do I need to act
 * on it. The payload holds enough to retry or to decide, so that acting on an
 * item never means going back to the logs.
 */
final class QueueItem {

  /**
   * Constructs the item.
   *
   * @param string $mapping
   *   The mapping the item belongs to.
   * @param string $reason
   *   A short explanation, readable without opening the payload.
   * @param string $entityTypeId
   *   The Drupal entity type ID, empty for an inbound item with no local
   *   counterpart yet.
   * @param string $entityId
   *   The Drupal entity ID.
   * @param string $remoteId
   *   The CRM record identifier.
   * @param int $attempts
   *   How many attempts were spent before giving up.
   * @param int $createdAt
   *   When the item was queued.
   * @param array<string, mixed> $payload
   *   Everything needed to retry or to decide.
   * @param int|null $id
   *   The row ID, NULL before the item is saved.
   */
  public function __construct(
    public readonly string $mapping,
    public readonly string $reason,
    public readonly string $entityTypeId = '',
    public readonly string $entityId = '',
    public readonly string $remoteId = '',
    public readonly int $attempts = 0,
    public readonly int $createdAt = 0,
    public readonly array $payload = [],
    public readonly ?int $id = NULL,
  ) {}

}
