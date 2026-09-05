<?php

declare(strict_types=1);

namespace Drupal\crm_bridge\Connector;

/**
 * One change the CRM is telling us about.
 */
final class WebhookEvent {

  /**
   * Constructs the event.
   *
   * @param string $object
   *   The remote object name.
   * @param string $remoteId
   *   The record that changed.
   * @param bool $deleted
   *   TRUE when the record was removed.
   * @param \Drupal\crm_bridge\Connector\RemoteRecord|null $record
   *   The record body, or NULL. NULL is normal rather than exceptional:
   *   several CRMs only ever say "record X changed", so the pull worker has to
   *   be able to re-fetch.
   */
  public function __construct(
    public readonly string $object,
    public readonly string $remoteId,
    public readonly bool $deleted = FALSE,
    public readonly ?RemoteRecord $record = NULL,
  ) {}

  /**
   * Whether the event carries the record body.
   *
   * @return bool
   *   TRUE when no re-fetch is needed.
   */
  public function isHydrated(): bool {
    return $this->record !== NULL;
  }

}
