<?php

declare(strict_types=1);

namespace Drupal\crm_bridge\Mapping;

/**
 * One row of a mapping: a Drupal field bound to a remote field.
 *
 * This is a value object rather than a plain array so that the rules about it
 * live in one place and can be unit tested without a container. The rule that
 * earns it its keep is direction narrowing: the effective direction of a field
 * is the intersection of the mapping's direction and the field's override, and
 * an empty intersection is a configuration error rather than a default.
 */
final class FieldMapping {

  /**
   * Constructs a field mapping.
   *
   * @param string $drupalField
   *   The Drupal field name.
   * @param string $remoteField
   *   The remote field name.
   * @param string $transform
   *   The transform identifier, or an empty string for none.
   * @param string $direction
   *   The per-field direction override, or an empty string for none.
   */
  public function __construct(
    public readonly string $drupalField,
    public readonly string $remoteField,
    public readonly string $transform = '',
    public readonly string $direction = '',
  ) {}

  /**
   * Builds a field mapping from its stored array form.
   *
   * @param array<string, mixed> $values
   *   The stored values.
   *
   * @return self
   *   The field mapping.
   */
  public static function fromArray(array $values): self {
    return new self(
      (string) ($values['drupal'] ?? ''),
      (string) ($values['remote'] ?? ''),
      (string) ($values['transform'] ?? ''),
      (string) ($values['direction'] ?? ''),
    );
  }

  /**
   * Returns the stored array form.
   *
   * @return array{drupal: string, remote: string, transform: string, direction: string}
   *   The stored values.
   */
  public function toArray(): array {
    return [
      'drupal' => $this->drupalField,
      'remote' => $this->remoteField,
      'transform' => $this->transform,
      'direction' => $this->direction,
    ];
  }

  /**
   * Resolves this field's direction inside a mapping.
   *
   * @param string $mappingDirection
   *   The mapping's direction.
   *
   * @return string|null
   *   The effective direction, or NULL when the override contradicts the
   *   mapping.
   */
  public function effectiveDirection(string $mappingDirection): ?string {
    return Direction::narrow($mappingDirection, $this->direction);
  }

  /**
   * Lists everything wrong with this field mapping.
   *
   * @param string $mappingDirection
   *   The mapping's direction, used to validate the override.
   *
   * @return list<string>
   *   Human-readable problems, empty when the mapping is valid.
   */
  public function validate(string $mappingDirection): array {
    $problems = [];

    if ($this->drupalField === '') {
      $problems[] = 'The Drupal field name is empty.';
    }
    if ($this->remoteField === '') {
      $problems[] = 'The remote field name is empty.';
    }
    if ($this->transform !== '' && !Transform::isKnown($this->transform)) {
      $problems[] = sprintf('Unknown transform "%s".', $this->transform);
    }
    if ($this->direction !== '' && !Direction::isValid($this->direction)) {
      $problems[] = sprintf('Unknown direction "%s".', $this->direction);
    }
    elseif ($this->direction !== '' && $this->effectiveDirection($mappingDirection) === NULL) {
      // Not a warning. A field set to pull inside a push-only mapping would
      // never sync, and silently ignoring it hides a real misconfiguration.
      $problems[] = sprintf(
        'Direction "%s" contradicts the mapping direction "%s", so this field would never sync.',
        $this->direction,
        $mappingDirection,
      );
    }

    return $problems;
  }

}
