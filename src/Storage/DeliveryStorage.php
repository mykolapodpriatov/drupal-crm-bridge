<?php

declare(strict_types=1);

namespace Drupal\crm_bridge\Storage;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;

/**
 * Remembers which webhook deliveries have already been handled.
 *
 * A CRM redelivering a webhook is normal rather than exceptional, and a
 * redelivery carries the same identifier as the original. Two different
 * identifiers are two events, and dropping one of those would drop a real
 * change, so deduplication keys on the identifier and never on content.
 */
class DeliveryStorage {

  /**
   * The table name.
   */
  private const TABLE = 'crm_bridge_delivery';

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
   * Claims a delivery, reporting whether it is new.
   *
   * The insert is the claim. Checking first and inserting second would let two
   * concurrent deliveries of the same identifier both look new, which is the
   * exact duplicate this table exists to prevent.
   *
   * @param string $connector
   *   The connector plugin ID.
   * @param string $deliveryId
   *   The identifier the CRM gave the delivery.
   * @param int $ttlSeconds
   *   How long to remember it. It only has to outlive the peer's redelivery
   *   window, so this table stays small.
   *
   * @return bool
   *   TRUE when the delivery is new and should be processed.
   */
  public function claim(string $connector, string $deliveryId, int $ttlSeconds): bool {
    $now = $this->time->getRequestTime();
    try {
      $this->database->insert(self::TABLE)
        ->fields([
          'connector' => $connector,
          'delivery_id' => $deliveryId,
          'received' => $now,
          'expires' => $now + $ttlSeconds,
        ])
        ->execute();
      return TRUE;
    }
    catch (IntegrityConstraintViolationException) {
      // Already claimed, by an earlier delivery or by a concurrent one.
      return FALSE;
    }
  }

  /**
   * Drops expired entries.
   *
   * @return int
   *   How many entries were dropped.
   */
  public function sweep(): int {
    return (int) $this->database->delete(self::TABLE)
      ->condition('expires', $this->time->getRequestTime(), '<=')
      ->execute();
  }

  /**
   * How many deliveries are remembered.
   *
   * @return int
   *   The count.
   */
  public function count(): int {
    return (int) $this->database->select(self::TABLE, 'd')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

}
