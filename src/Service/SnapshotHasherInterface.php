<?php

declare(strict_types=1);

namespace Drupal\crm_bridge\Service;

/**
 * Computes stable digests over the mapped fields of a record.
 */
interface SnapshotHasherInterface {

  /**
   * Hashes the named fields of a value set.
   *
   * @param array<string, mixed> $values
   *   Field values keyed by canonical field name. Values not named in
   *   $names are ignored.
   * @param list<string> $names
   *   The canonical field names that take part in the digest.
   *
   * @return string
   *   A hex-encoded SHA-256 digest.
   */
  public function hash(array $values, array $names): string;

}
