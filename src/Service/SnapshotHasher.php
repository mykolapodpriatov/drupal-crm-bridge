<?php

declare(strict_types=1);

namespace Drupal\crm_bridge\Service;

/**
 * Digests the mapped fields of a record.
 *
 * This is the primitive two separate mechanisms are built on, which is why it
 * is one service rather than a private helper in each:
 *
 * - Conflict detection compares the current digest against the digest stored
 *   at the last successful sync. That is what distinguishes "changed since we
 *   synced" from "changed in this request", and only the first one matters
 *   after a failed queue run.
 * - Echo suppression folds the digest into the origin tag, so a write this
 *   module performed is recognised when it comes back as a webhook, even when
 *   the CRM does not report who made the change.
 *
 * Three properties are deliberate and covered by tests:
 *
 * - Only named fields count, so an unmapped field churning on either side
 *   cannot trigger a write storm.
 * - Every part is length-prefixed, so field boundaries cannot be repacked
 *   into the same digest.
 * - Whole floats render as integers, so a JSON round-trip that turns 1 into
 *   1.0 does not read as a change.
 */
final class SnapshotHasher implements SnapshotHasherInterface {

  /**
   * {@inheritdoc}
   */
  public function hash(array $values, array $names): string {
    $names = array_values(array_unique($names));
    sort($names, SORT_STRING);

    $buffer = '';
    foreach ($names as $name) {
      $buffer .= $this->chunk($name);
      $buffer .= array_key_exists($name, $values)
        ? $this->chunk($this->canonicalise($values[$name]))
        : $this->chunk("\0absent");
    }

    return hash('sha256', $buffer);
  }

  /**
   * Length-prefixes one part so adjacent parts cannot collide.
   *
   * Without this, the field pair ("ab", "c") and the pair ("a", "bc") produce
   * the same byte stream and therefore the same digest.
   *
   * @param string $part
   *   The part to encode.
   *
   * @return string
   *   The encoded part.
   */
  private function chunk(string $part): string {
    return strlen($part) . ':' . $part;
  }

  /**
   * Renders a value so equal-for-sync values render identically.
   *
   * @param mixed $value
   *   The value to render.
   *
   * @return string
   *   A canonical string rendering, tagged by type so that the string "1"
   *   and the integer 1 stay distinguishable.
   */
  private function canonicalise(mixed $value): string {
    if ($value === NULL) {
      return "\0null";
    }
    if (is_bool($value)) {
      return 'b' . ($value ? '1' : '0');
    }
    if (is_int($value)) {
      return 'n' . $value;
    }
    if (is_float($value)) {
      return $this->numeric($value);
    }
    if (is_string($value)) {
      return 's' . $value;
    }
    if ($value instanceof \DateTimeInterface) {
      $utc = \DateTimeImmutable::createFromInterface($value)
        ->setTimezone(new \DateTimeZone('UTC'));
      return 't' . $utc->format('Y-m-d\TH:i:s.uP');
    }
    if (is_array($value)) {
      return 'a' . $this->renderArray($value);
    }

    return 'x' . var_export($value, TRUE);
  }

  /**
   * Renders a float, collapsing whole values onto their integer rendering.
   *
   * @param float $value
   *   The value to render.
   *
   * @return string
   *   The canonical rendering.
   */
  private function numeric(float $value): string {
    if (is_finite($value) && $value === floor($value)
      && abs($value) < (float) PHP_INT_MAX) {
      return 'n' . (int) $value;
    }

    return 'n' . rtrim(rtrim(sprintf('%.17G', $value), '0'), '.');
  }

  /**
   * Renders an array, sorting string-keyed arrays for order independence.
   *
   * Lists keep their order, because the order of a multi-value field is
   * meaningful. Associative arrays do not, because a CRM is free to return
   * their keys in any order.
   *
   * @param array<array-key, mixed> $value
   *   The array to render.
   *
   * @return string
   *   The canonical rendering.
   */
  private function renderArray(array $value): string {
    if (!array_is_list($value)) {
      ksort($value, SORT_STRING);
    }

    $out = '';
    foreach ($value as $key => $item) {
      $out .= $this->chunk((string) $key);
      $out .= $this->chunk($this->canonicalise($item));
    }

    return $out;
  }

}
