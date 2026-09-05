<?php

declare(strict_types=1);

namespace Drupal\crm_bridge\Mapping;

/**
 * The directions a mapping or a single field may sync in.
 *
 * Directions are modelled as a bit set so that combining a mapping's direction
 * with a per-field override is an intersection rather than a table of special
 * cases. That matters because the rule is one-way: a field may narrow what the
 * mapping does, never widen it. A mapping configured to push must not acquire
 * a pull because somebody set one field to `pull`, since that would let the
 * CRM write into Drupal on a mapping whose whole point was that it does not.
 */
final class Direction {

  /**
   * Drupal to CRM.
   */
  public const PUSH = 'push';

  /**
   * CRM to Drupal.
   */
  public const PULL = 'pull';

  /**
   * Both ways.
   */
  public const BIDIRECTIONAL = 'bidirectional';

  private const BIT_PUSH = 1;
  private const BIT_PULL = 2;

  private const BITS = [
    self::PUSH => self::BIT_PUSH,
    self::PULL => self::BIT_PULL,
    self::BIDIRECTIONAL => self::BIT_PUSH | self::BIT_PULL,
  ];

  /**
   * Every valid direction value.
   *
   * @return list<string>
   *   The direction identifiers.
   */
  public static function all(): array {
    return array_keys(self::BITS);
  }

  /**
   * Checks whether a value is a known direction.
   *
   * @param string $direction
   *   The value to check.
   *
   * @return bool
   *   TRUE when the value is a known direction.
   */
  public static function isValid(string $direction): bool {
    return isset(self::BITS[$direction]);
  }

  /**
   * Narrows a mapping's direction by a per-field override.
   *
   * @param string $mapping
   *   The mapping's direction.
   * @param string $field
   *   The field's override, or an empty string for no override.
   *
   * @return string|null
   *   The effective direction, or NULL when the override contradicts the
   *   mapping and leaves nothing to sync. NULL is a configuration error the
   *   caller must surface, not a direction to fall back from.
   */
  public static function narrow(string $mapping, string $field): ?string {
    if (!self::isValid($mapping)) {
      return NULL;
    }
    if ($field === '') {
      return $mapping;
    }
    if (!self::isValid($field)) {
      return NULL;
    }

    $bits = self::BITS[$mapping] & self::BITS[$field];
    return match ($bits) {
      self::BIT_PUSH => self::PUSH,
      self::BIT_PULL => self::PULL,
      self::BIT_PUSH | self::BIT_PULL => self::BIDIRECTIONAL,
      default => NULL,
    };
  }

  /**
   * Whether a direction carries Drupal changes to the CRM.
   *
   * @param string $direction
   *   The direction.
   *
   * @return bool
   *   TRUE when the direction includes a push.
   */
  public static function pushes(string $direction): bool {
    return (bool) ((self::BITS[$direction] ?? 0) & self::BIT_PUSH);
  }

  /**
   * Whether a direction carries CRM changes to Drupal.
   *
   * @param string $direction
   *   The direction.
   *
   * @return bool
   *   TRUE when the direction includes a pull.
   */
  public static function pulls(string $direction): bool {
    return (bool) ((self::BITS[$direction] ?? 0) & self::BIT_PULL);
  }

}
