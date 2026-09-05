<?php

declare(strict_types=1);

namespace Drupal\crm_bridge\Connector;

/**
 * A classified failure from a connector.
 */
class ConnectorException extends \RuntimeException {

  /**
   * Constructs the exception.
   *
   * @param string $message
   *   The failure description.
   * @param \Drupal\crm_bridge\Connector\FailureKind $kind
   *   How the failure should be handled.
   * @param int $retryAfterSeconds
   *   What the peer asked us to wait, or 0 when it said nothing. Zero is the
   *   case the caller's own backoff has to cover.
   * @param \Throwable|null $previous
   *   The underlying error.
   */
  public function __construct(
    string $message,
    public readonly FailureKind $kind = FailureKind::Unknown,
    public readonly int $retryAfterSeconds = 0,
    ?\Throwable $previous = NULL,
  ) {
    parent::__construct($message, 0, $previous);
  }

  /**
   * Whether retrying this failure could plausibly succeed.
   *
   * @return bool
   *   TRUE when the caller should retry.
   */
  public function isRetryable(): bool {
    return $this->kind->isRetryable();
  }

}
