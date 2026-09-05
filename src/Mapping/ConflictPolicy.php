<?php

declare(strict_types=1);

namespace Drupal\crm_bridge\Mapping;

/**
 * The policies available when both sides changed since the last sync.
 *
 * REVIEW is the default everywhere it is not stated, and that is deliberate.
 * A half-configured mapping must never pick a winner on its own: writing the
 * wrong side into a CRM is not something an operator can see happening, and
 * queuing the conflict costs nothing but a row.
 */
final class ConflictPolicy {

  /**
   * The Drupal value wins.
   */
  public const DRUPAL_WINS = 'drupal_wins';

  /**
   * The CRM value wins.
   */
  public const CRM_WINS = 'crm_wins';

  /**
   * The most recently modified side wins.
   *
   * This trusts a remote clock, which is why the resolver applies a skew
   * tolerance rather than believing a millisecond difference.
   */
  public const NEWEST_WINS = 'newest_wins';

  /**
   * Non-overlapping field edits merge; overlapping ones escalate.
   */
  public const FIELD_LEVEL = 'field_level';

  /**
   * Nothing is written and both versions are queued for a human.
   */
  public const REVIEW = 'review';

  /**
   * Every valid policy.
   *
   * @return list<string>
   *   The policy identifiers.
   */
  public static function all(): array {
    return [
      self::DRUPAL_WINS,
      self::CRM_WINS,
      self::NEWEST_WINS,
      self::FIELD_LEVEL,
      self::REVIEW,
    ];
  }

  /**
   * Checks whether a value is a known policy.
   *
   * @param string $policy
   *   The value to check.
   *
   * @return bool
   *   TRUE when the value is a known policy.
   */
  public static function isValid(string $policy): bool {
    return in_array($policy, self::all(), TRUE);
  }

  /**
   * Whether a policy can resolve without a human.
   *
   * @param string $policy
   *   The policy.
   *
   * @return bool
   *   TRUE when the policy decides on its own.
   */
  public static function isAutomatic(string $policy): bool {
    return $policy !== self::REVIEW;
  }

}
