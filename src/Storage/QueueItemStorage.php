<?php

declare(strict_types=1);

namespace Drupal\crm_bridge\Storage;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Reads and writes the dead-letter and review queues.
 *
 * One class for both tables, because they hold the same envelope and differ
 * only in what put an item there. Two near-identical storage classes would
 * drift, and the drift would show up as the review queue quietly missing
 * whatever the dead-letter queue learned to store.
 */
class QueueItemStorage {

  /**
   * The dead-letter table.
   */
  public const DLQ = 'crm_bridge_dlq';

  /**
   * The review table.
   */
  public const REVIEW = 'crm_bridge_review';

  /**
   * Constructs the storage.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    protected readonly Connection $database,
    protected readonly TimeInterface $time,
  ) {}

  /**
   * Adds an item.
   *
   * @param string $table
   *   Either self::DLQ or self::REVIEW.
   * @param \Drupal\crm_bridge\Storage\QueueItem $item
   *   The item.
   *
   * @return int
   *   The new row ID.
   */
  public function add(string $table, QueueItem $item): int {
    $this->assertTable($table);

    return (int) $this->database->insert($table)
      ->fields([
        'mapping' => $item->mapping,
        'entity_type' => $item->entityTypeId,
        'entity_id' => $item->entityId,
        'remote_id' => $item->remoteId,
        // Truncated rather than rejected: an over-long reason must not be the
        // thing that stops a failure being recorded.
        'reason' => mb_substr($item->reason, 0, 255),
        'attempts' => $item->attempts,
        'created' => $item->createdAt !== 0 ? $item->createdAt : $this->time->getRequestTime(),
        'payload' => $item->payload === [] ? NULL : json_encode($item->payload, JSON_THROW_ON_ERROR),
      ])
      ->execute();
  }

  /**
   * Loads one item.
   *
   * @param string $table
   *   Either self::DLQ or self::REVIEW.
   * @param int $id
   *   The row ID.
   *
   * @return \Drupal\crm_bridge\Storage\QueueItem|null
   *   The item, or NULL.
   */
  public function load(string $table, int $id): ?QueueItem {
    $this->assertTable($table);

    $row = $this->database->select($table, 'q')
      ->fields('q')
      ->condition('id', $id)
      ->execute()
      ->fetchAssoc();

    return $row === FALSE ? NULL : $this->hydrate($row);
  }

  /**
   * Lists items, oldest first.
   *
   * @param string $table
   *   Either self::DLQ or self::REVIEW.
   * @param string $mapping
   *   Restrict to one mapping, or an empty string for all of them.
   * @param int $limit
   *   How many to return.
   *
   * @return list<\Drupal\crm_bridge\Storage\QueueItem>
   *   The items.
   */
  public function list(string $table, string $mapping = '', int $limit = 50): array {
    $this->assertTable($table);

    $query = $this->database->select($table, 'q')
      ->fields('q')
      ->orderBy('created')
      ->orderBy('id')
      ->range(0, $limit);
    if ($mapping !== '') {
      $query->condition('mapping', $mapping);
    }

    $rows = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
    return array_values(array_map($this->hydrate(...), $rows));
  }

  /**
   * Counts items.
   *
   * @param string $table
   *   Either self::DLQ or self::REVIEW.
   * @param string $mapping
   *   Restrict to one mapping, or an empty string for all of them.
   *
   * @return int
   *   The count.
   */
  public function count(string $table, string $mapping = ''): int {
    $this->assertTable($table);

    $query = $this->database->select($table, 'q');
    if ($mapping !== '') {
      $query->condition('mapping', $mapping);
    }
    return (int) $query->countQuery()->execute()->fetchField();
  }

  /**
   * Removes an item.
   *
   * @param string $table
   *   Either self::DLQ or self::REVIEW.
   * @param int $id
   *   The row ID.
   *
   * @return bool
   *   TRUE when a row was removed.
   */
  public function delete(string $table, int $id): bool {
    $this->assertTable($table);

    return (bool) $this->database->delete($table)
      ->condition('id', $id)
      ->execute();
  }

  /**
   * Removes every item belonging to a mapping.
   *
   * Called when a mapping is deleted, so that its failures do not outlive it
   * and confuse whoever reads the queue next.
   *
   * @param string $table
   *   Either self::DLQ or self::REVIEW.
   * @param string $mapping
   *   The mapping ID.
   *
   * @return int
   *   How many rows were removed.
   */
  public function deleteByMapping(string $table, string $mapping): int {
    $this->assertTable($table);

    return (int) $this->database->delete($table)
      ->condition('mapping', $mapping)
      ->execute();
  }

  /**
   * Builds an item from a database row.
   *
   * @param array<string, mixed> $row
   *   The row.
   *
   * @return \Drupal\crm_bridge\Storage\QueueItem
   *   The item.
   */
  private function hydrate(array $row): QueueItem {
    $payload = [];
    if (is_string($row['payload'] ?? NULL) && $row['payload'] !== '') {
      $decoded = json_decode((string) $row['payload'], TRUE);
      $payload = is_array($decoded) ? $decoded : [];
    }

    return new QueueItem(
      (string) $row['mapping'],
      (string) $row['reason'],
      (string) $row['entity_type'],
      (string) $row['entity_id'],
      (string) $row['remote_id'],
      (int) $row['attempts'],
      (int) $row['created'],
      $payload,
      (int) $row['id'],
    );
  }

  /**
   * Rejects a table name this class does not own.
   *
   * The table is a parameter, so this is the boundary that keeps it from ever
   * becoming one an attacker chose.
   *
   * @param string $table
   *   The table name.
   */
  private function assertTable(string $table): void {
    if ($table !== self::DLQ && $table !== self::REVIEW) {
      throw new \InvalidArgumentException(sprintf('Unknown queue table "%s".', $table));
    }
  }

}
