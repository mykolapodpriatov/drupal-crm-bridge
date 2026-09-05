<?php

declare(strict_types=1);

namespace Drupal\crm_bridge\Storage;

/**
 * One Drupal entity bound to one CRM record.
 */
final class Link {

  /**
   * Constructs the link.
   *
   * @param string $mapping
   *   The mapping ID.
   * @param string $entityTypeId
   *   The Drupal entity type ID.
   * @param string $entityId
   *   The Drupal entity ID.
   * @param string $connector
   *   The connector plugin ID.
   * @param string $remoteId
   *   The CRM record identifier.
   * @param string $localHash
   *   Digest of the mapped Drupal fields at the last successful sync.
   * @param string $remoteHash
   *   Digest of the mapped CRM fields at the last successful sync.
   * @param array<string, mixed> $localValues
   *   The mapped Drupal values at the last successful sync.
   * @param array<string, mixed> $remoteValues
   *   The mapped CRM values at the last successful sync.
   * @param int $syncedAt
   *   When the last successful sync happened.
   * @param int|null $id
   *   The row ID, NULL before the link is saved.
   */
  public function __construct(
    public readonly string $mapping,
    public readonly string $entityTypeId,
    public readonly string $entityId,
    public readonly string $connector,
    public readonly string $remoteId,
    public readonly string $localHash = '',
    public readonly string $remoteHash = '',
    public readonly array $localValues = [],
    public readonly array $remoteValues = [],
    public readonly int $syncedAt = 0,
    public readonly ?int $id = NULL,
  ) {}

  /**
   * Returns a copy with the sync state replaced.
   *
   * @param string $localHash
   *   The new local digest.
   * @param string $remoteHash
   *   The new remote digest.
   * @param array<string, mixed> $localValues
   *   The new local values.
   * @param array<string, mixed> $remoteValues
   *   The new remote values.
   * @param int $syncedAt
   *   When this sync happened.
   *
   * @return self
   *   The updated link.
   */
  public function withSyncState(
    string $localHash,
    string $remoteHash,
    array $localValues,
    array $remoteValues,
    int $syncedAt,
  ): self {
    return new self(
      $this->mapping,
      $this->entityTypeId,
      $this->entityId,
      $this->connector,
      $this->remoteId,
      $localHash,
      $remoteHash,
      $localValues,
      $remoteValues,
      $syncedAt,
      $this->id,
    );
  }

}
