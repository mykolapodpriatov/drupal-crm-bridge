<?php

declare(strict_types=1);

namespace Drupal\crm_bridge\Connector;

/**
 * One field on a remote object.
 */
final class RemoteField {

  /**
   * Constructs the field.
   *
   * @param string $name
   *   The remote field name.
   * @param string $type
   *   The peer's type name, for display and for doctor's output.
   * @param bool $readOnly
   *   TRUE when the peer refuses writes to this field. Mapping validation
   *   rejects a direction that writes to it, so the failure happens once at
   *   install rather than in every dead-letter entry.
   * @param bool $required
   *   TRUE when the peer refuses a record without it.
   */
  public function __construct(
    public readonly string $name,
    public readonly string $type = 'string',
    public readonly bool $readOnly = FALSE,
    public readonly bool $required = FALSE,
  ) {}

}
