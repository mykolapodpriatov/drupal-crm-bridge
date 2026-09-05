<?php

declare(strict_types=1);

namespace Drupal\crm_bridge\Connector;

/**
 * Classifies a connector failure.
 *
 * The queue worker has to choose between retrying, backing off and
 * dead-lettering without parsing exception messages or knowing which CRM
 * produced them. That decision is what this enum exists to carry.
 */
enum FailureKind: string {

  // A network failure or a 5xx. Retry with backoff.
  case Transient = 'transient';

  // The peer's quota is exhausted. Back off, then resume the same batch.
  case RateLimited = 'rate_limited';

  // A request the peer will never accept, such as a value that fails an
  // account-level validation rule. Retrying only burns quota, so it goes
  // straight to the dead-letter queue with the reason attached.
  case Permanent = 'permanent';

  // The record is gone.
  case NotFound = 'not_found';

  // The credentials are wrong or expired. Retrying makes it worse and it needs
  // a person, so it is surfaced on the status report rather than buried in the
  // queue.
  case Auth = 'auth';

  // Something we did not classify.
  case Unknown = 'unknown';

  /**
   * Whether trying again could plausibly succeed.
   *
   * Unknown is retryable on purpose. Giving up on a failure we do not
   * understand loses data, and retrying it costs a request.
   *
   * @return bool
   *   TRUE when the caller should retry.
   */
  public function isRetryable(): bool {
    return match ($this) {
      self::Transient, self::RateLimited, self::Unknown => TRUE,
      self::Permanent, self::NotFound, self::Auth => FALSE,
    };
  }

}
