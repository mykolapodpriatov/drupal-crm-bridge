<?php

declare(strict_types=1);

namespace Drupal\crm_bridge\Mapping;

/**
 * The value transforms a field mapping may apply.
 *
 * Normalisation is not cosmetic here: the same transforms feed deterministic
 * identity matching, so if "A@B.com" and "a@b.com" do not normalise to one
 * value, the module creates a duplicate contact instead of finding the
 * existing one.
 */
final class Transform {

  /**
   * Lower-cases and trims an address, and strips a trailing dot from the host.
   */
  public const EMAIL_NORMALIZE = 'email_normalize';

  /**
   * Lower-cases a host and strips a leading "www." and a trailing dot.
   */
  public const DOMAIN_NORMALIZE = 'domain_normalize';

  /**
   * Trims surrounding whitespace.
   */
  public const TRIM = 'trim';

  /**
   * Lower-cases the value.
   */
  public const LOWERCASE = 'lowercase';

  /**
   * Every known transform.
   *
   * @return list<string>
   *   The transform identifiers.
   */
  public static function all(): array {
    return [
      self::EMAIL_NORMALIZE,
      self::DOMAIN_NORMALIZE,
      self::TRIM,
      self::LOWERCASE,
    ];
  }

  /**
   * Checks whether a transform identifier is known.
   *
   * @param string $transform
   *   The identifier.
   *
   * @return bool
   *   TRUE when the transform exists.
   */
  public static function isKnown(string $transform): bool {
    return in_array($transform, self::all(), TRUE);
  }

}
