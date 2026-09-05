<?php

declare(strict_types=1);

namespace Drupal\crm_bridge\Connector;

/**
 * The fields a remote object has.
 *
 * Mapping validation compares this against the configured field map, which is
 * the difference between a renamed property failing at install with the field
 * name in the message and failing at three in the morning as a half-landed
 * write.
 */
final class RemoteSchema {

  /**
   * Constructs the schema.
   *
   * @param string $object
   *   The remote object name.
   * @param array<string, \Drupal\crm_bridge\Connector\RemoteField> $fields
   *   The fields, keyed by remote field name.
   */
  public function __construct(
    public readonly string $object,
    public readonly array $fields = [],
  ) {}

  /**
   * Whether the object has a field.
   *
   * @param string $name
   *   The remote field name.
   *
   * @return bool
   *   TRUE when the field exists.
   */
  public function has(string $name): bool {
    return isset($this->fields[$name]);
  }

  /**
   * Returns one field.
   *
   * @param string $name
   *   The remote field name.
   *
   * @return \Drupal\crm_bridge\Connector\RemoteField|null
   *   The field, or NULL when it does not exist.
   */
  public function get(string $name): ?RemoteField {
    return $this->fields[$name] ?? NULL;
  }

  /**
   * Every field name.
   *
   * @return list<string>
   *   The names.
   */
  public function names(): array {
    return array_keys($this->fields);
  }

}
