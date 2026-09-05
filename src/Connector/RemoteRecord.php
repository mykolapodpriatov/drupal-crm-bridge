<?php

declare(strict_types=1);

namespace Drupal\crm_bridge\Connector;

/**
 * One object as it exists in the CRM.
 */
final class RemoteRecord {

  /**
   * Constructs the record.
   *
   * @param string $remoteId
   *   The peer's identifier, empty for a record that has not been created yet.
   * @param array<string, mixed> $fields
   *   The remote field values, keyed by remote field name.
   * @param int $updatedAt
   *   The peer's modification timestamp, as a Unix time.
   * @param string $version
   *   The peer's version token, empty when it does not issue one.
   */
  public function __construct(
    public readonly string $remoteId,
    public readonly array $fields,
    public readonly int $updatedAt = 0,
    public readonly string $version = '',
  ) {}

  /**
   * Reads one field.
   *
   * @param string $name
   *   The remote field name.
   * @param mixed $default
   *   What to return when the field is absent.
   *
   * @return mixed
   *   The value.
   */
  public function get(string $name, mixed $default = NULL): mixed {
    return $this->fields[$name] ?? $default;
  }

  /**
   * Whether the record carries a field at all.
   *
   * Distinct from the value being empty. A peer that omitted a field has not
   * told us it is empty, and treating the two the same turns a partial
   * response into a deletion.
   *
   * @param string $name
   *   The remote field name.
   *
   * @return bool
   *   TRUE when the field is present.
   */
  public function has(string $name): bool {
    return array_key_exists($name, $this->fields);
  }

}
