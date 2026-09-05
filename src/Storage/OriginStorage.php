<?php

declare(strict_types=1);

namespace Drupal\crm_bridge\Storage;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * The write log echo suppression consumes.
 *
 * Entries are single-use. If a write is followed by a genuine second change
 * carrying the same payload, only the first inbound event is our echo, and
 * suppressing both would drop a real change. Consumption therefore has to be
 * atomic: two queue workers seeing the same tag must not both be told it was
 * theirs.
 */
class OriginStorage {

  /**
   * The table name.
   */
  private const TABLE = 'crm_bridge_origin';

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
   * Builds the tag for a write.
   *
   * The payload digest is folded in on purpose. Several CRMs do not report who
   * made a change, and where they do, an automation on the peer can rewrite
   * the record under our own user milliseconds later, so filtering on the
   * actor drops that genuine change. We recognise our own write by what it
   * said rather than by who said it.
   *
   * @param string $connector
   *   The connector plugin ID.
   * @param string $object
   *   The remote object name.
   * @param string $remoteId
   *   The record identifier.
   * @param string $payloadHash
   *   Digest of the mapped fields that were written.
   *
   * @return string
   *   The tag.
   */
  public static function tag(string $connector, string $object, string $remoteId, string $payloadHash): string {
    return hash('sha256', implode("\0", [$connector, $object, $remoteId, $payloadHash]));
  }

  /**
   * Records a write.
   *
   * @param string $mapping
   *   The mapping that made the write.
   * @param string $tag
   *   The tag, from self::tag().
   * @param int $ttlSeconds
   *   How long the entry suppresses for. It has to comfortably exceed the
   *   peer's webhook delivery latency: an entry that expires before our own
   *   write comes back is the start of a loop.
   */
  public function record(string $mapping, string $tag, int $ttlSeconds): void {
    $this->database->merge(self::TABLE)
      ->key('tag', $tag)
      ->fields([
        'mapping' => $mapping,
        'expires' => $this->time->getRequestTime() + $ttlSeconds,
      ])
      ->execute();
  }

  /**
   * Consumes an entry, reporting whether the change was ours.
   *
   * The delete is the claim: whichever caller's DELETE affected a row is the
   * one that gets to call the change an echo. A SELECT followed by a DELETE
   * would let two callers through the gap between them.
   *
   * @param string $tag
   *   The tag, from self::tag().
   *
   * @return bool
   *   TRUE when this module caused the change.
   */
  public function claim(string $tag): bool {
    $deleted = (int) $this->database->delete(self::TABLE)
      ->condition('tag', $tag)
      ->condition('expires', $this->time->getRequestTime(), '>')
      ->execute();

    return $deleted > 0;
  }

  /**
   * Whether this module wrote anything to a record inside its window.
   *
   * A record we touched recently, with a payload that does not match, means
   * the peer rewrote what we sent. That is a real change and is processed, but
   * it is worth counting: a rising rate of it is the early warning that an
   * automation on the peer is fighting the sync.
   *
   * @param string $connector
   *   The connector plugin ID.
   * @param string $object
   *   The remote object name.
   * @param string $remoteId
   *   The record identifier.
   *
   * @return bool
   *   TRUE when a live entry exists for the record.
   */
  public function touchedRecently(string $connector, string $object, string $remoteId): bool {
    // The marker is filed under a payload hash no real digest can produce, so
    // it cannot be claimed by claim() and cannot collide with a real entry.
    return (bool) $this->database->select(self::TABLE, 'o')
      ->condition('tag', self::tag($connector, $object, $remoteId, '*recent'))
      ->condition('expires', $this->time->getRequestTime(), '>')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Records that this module touched a record, whatever the payload was.
   *
   * @param string $mapping
   *   The mapping that made the write.
   * @param string $connector
   *   The connector plugin ID.
   * @param string $object
   *   The remote object name.
   * @param string $remoteId
   *   The record identifier.
   * @param int $ttlSeconds
   *   How long to remember it.
   */
  public function markTouched(string $mapping, string $connector, string $object, string $remoteId, int $ttlSeconds): void {
    $this->record($mapping, self::tag($connector, $object, $remoteId, '*recent'), $ttlSeconds);
  }

  /**
   * Drops expired entries.
   *
   * Expiry is already invisible to claim(), so this only exists to stop the
   * table growing without bound.
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
   * How many live entries exist.
   *
   * @return int
   *   The count.
   */
  public function countLive(): int {
    return (int) $this->database->select(self::TABLE, 'o')
      ->condition('expires', $this->time->getRequestTime(), '>')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

}
