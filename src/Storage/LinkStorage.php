<?php

declare(strict_types=1);

namespace Drupal\crm_bridge\Storage;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;

/**
 * Reads and writes the link table.
 *
 * This is the only data this module owns that a resync cannot rebuild.
 * Watermarks, queues and the origin log are all derivable; link rows are not,
 * and losing them means every record is created a second time.
 */
class LinkStorage {

  /**
   * The table name.
   */
  private const TABLE = 'crm_bridge_link';

  /**
   * Constructs the storage.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(
    protected readonly Connection $database,
  ) {}

  /**
   * Finds the link for a Drupal entity.
   *
   * @param string $mapping
   *   The mapping ID.
   * @param string $entityTypeId
   *   The entity type ID.
   * @param string $entityId
   *   The entity ID.
   *
   * @return \Drupal\crm_bridge\Storage\Link|null
   *   The link, or NULL when the entity has never synced.
   */
  public function findByEntity(string $mapping, string $entityTypeId, string $entityId): ?Link {
    $row = $this->database->select(self::TABLE, 'l')
      ->fields('l')
      ->condition('mapping', $mapping)
      ->condition('entity_type', $entityTypeId)
      ->condition('entity_id', $entityId)
      ->execute()
      ->fetchAssoc();

    return $row === FALSE ? NULL : $this->hydrate($row);
  }

  /**
   * Finds the link for a CRM record.
   *
   * @param string $mapping
   *   The mapping ID.
   * @param string $connector
   *   The connector plugin ID.
   * @param string $remoteId
   *   The CRM record identifier.
   *
   * @return \Drupal\crm_bridge\Storage\Link|null
   *   The link, or NULL when the record has never synced.
   */
  public function findByRemote(string $mapping, string $connector, string $remoteId): ?Link {
    $row = $this->database->select(self::TABLE, 'l')
      ->fields('l')
      ->condition('mapping', $mapping)
      ->condition('connector', $connector)
      ->condition('remote_id', $remoteId)
      ->execute()
      ->fetchAssoc();

    return $row === FALSE ? NULL : $this->hydrate($row);
  }

  /**
   * Creates or updates a link.
   *
   * Uses an upsert keyed on the Drupal side, so that two queue workers racing
   * to link the same entity produce one row rather than a constraint failure
   * one of them has to interpret.
   *
   * @param \Drupal\crm_bridge\Storage\Link $link
   *   The link to store.
   *
   * @throws \Drupal\crm_bridge\Storage\LinkConflictException
   *   When the CRM record is already linked to a different Drupal entity.
   *   Overwriting silently would leave two entities pointing at one record and
   *   quietly stop syncing one of them.
   */
  public function save(Link $link): void {
    $existing = $this->findByRemote($link->mapping, $link->connector, $link->remoteId);
    if (
      $existing !== NULL
      && ($existing->entityTypeId !== $link->entityTypeId || $existing->entityId !== $link->entityId)
    ) {
      throw new LinkConflictException(sprintf(
        'CRM record %s:%s is already linked to %s:%s in mapping %s.',
        $link->connector,
        $link->remoteId,
        $existing->entityTypeId,
        $existing->entityId,
        $link->mapping,
      ));
    }

    $values = [
      'connector' => $link->connector,
      'remote_id' => $link->remoteId,
      'local_hash' => $link->localHash,
      'remote_hash' => $link->remoteHash,
      'local_values' => $this->encode($link->localValues),
      'remote_values' => $this->encode($link->remoteValues),
      'synced' => $link->syncedAt,
    ];

    try {
      $this->database->merge(self::TABLE)
        ->keys([
          'mapping' => $link->mapping,
          'entity_type' => $link->entityTypeId,
          'entity_id' => $link->entityId,
        ])
        ->fields($values)
        ->execute();
    }
    catch (IntegrityConstraintViolationException $e) {
      // The unique key on the remote side fired, which means another process
      // linked that CRM record between the check above and this write.
      throw new LinkConflictException(sprintf(
        'CRM record %s:%s was linked by another process while this link was being written.',
        $link->connector,
        $link->remoteId,
      ), 0, $e);
    }
  }

  /**
   * Removes a link.
   *
   * @param string $mapping
   *   The mapping ID.
   * @param string $entityTypeId
   *   The entity type ID.
   * @param string $entityId
   *   The entity ID.
   *
   * @return bool
   *   TRUE when a row was removed.
   */
  public function delete(string $mapping, string $entityTypeId, string $entityId): bool {
    return (bool) $this->database->delete(self::TABLE)
      ->condition('mapping', $mapping)
      ->condition('entity_type', $entityTypeId)
      ->condition('entity_id', $entityId)
      ->execute();
  }

  /**
   * Counts the links a mapping holds.
   *
   * @param string $mapping
   *   The mapping ID.
   *
   * @return int
   *   The count.
   */
  public function count(string $mapping): int {
    return (int) $this->database->select(self::TABLE, 'l')
      ->condition('mapping', $mapping)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Lists links that have not synced since a timestamp, oldest first.
   *
   * This is what the drift audit walks, so it is ordered and limited rather
   * than loading a whole table into memory.
   *
   * @param string $mapping
   *   The mapping ID.
   * @param int $before
   *   Only links whose last sync is older than this.
   * @param int $limit
   *   How many to return.
   *
   * @return list<\Drupal\crm_bridge\Storage\Link>
   *   The links.
   */
  public function findStale(string $mapping, int $before, int $limit = 50): array {
    $rows = $this->database->select(self::TABLE, 'l')
      ->fields('l')
      ->condition('mapping', $mapping)
      ->condition('synced', $before, '<')
      ->orderBy('synced')
      ->range(0, $limit)
      ->execute()
      ->fetchAllAssoc('id', \PDO::FETCH_ASSOC);

    return array_values(array_map($this->hydrate(...), $rows));
  }

  /**
   * Builds a link from a database row.
   *
   * @param array<string, mixed> $row
   *   The row.
   *
   * @return \Drupal\crm_bridge\Storage\Link
   *   The link.
   */
  private function hydrate(array $row): Link {
    return new Link(
      (string) $row['mapping'],
      (string) $row['entity_type'],
      (string) $row['entity_id'],
      (string) $row['connector'],
      (string) $row['remote_id'],
      (string) $row['local_hash'],
      (string) $row['remote_hash'],
      $this->decode($row['local_values'] ?? NULL),
      $this->decode($row['remote_values'] ?? NULL),
      (int) $row['synced'],
      (int) $row['id'],
    );
  }

  /**
   * Encodes stored values.
   *
   * @param array<string, mixed> $values
   *   The values.
   *
   * @return string|null
   *   The JSON, or NULL when there is nothing to store.
   */
  private function encode(array $values): ?string {
    if ($values === []) {
      return NULL;
    }
    return json_encode($values, JSON_THROW_ON_ERROR);
  }

  /**
   * Decodes stored values.
   *
   * A row written by an older release, or by a hand-edited database, must not
   * take the sync down. An unreadable base means the merge has no base, which
   * the conflict resolver already handles by refusing to guess.
   *
   * @param mixed $raw
   *   The stored JSON.
   *
   * @return array<string, mixed>
   *   The values, empty when there are none or they cannot be read.
   */
  private function decode(mixed $raw): array {
    if (!is_string($raw) || $raw === '') {
      return [];
    }
    $decoded = json_decode($raw, TRUE);
    return is_array($decoded) ? $decoded : [];
  }

}
