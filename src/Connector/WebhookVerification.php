<?php

declare(strict_types=1);

namespace Drupal\crm_bridge\Connector;

/**
 * The outcome of authenticating an inbound delivery.
 */
final class WebhookVerification {

  /**
   * Constructs the verification.
   *
   * @param string $deliveryId
   *   The peer's delivery identifier, used to recognise a redelivery. A
   *   redelivery carries the same ID; two different IDs are two events, and
   *   dropping one of those would drop a real change.
   * @param list<\Drupal\crm_bridge\Connector\WebhookEvent> $events
   *   The events the delivery carries.
   */
  public function __construct(
    public readonly string $deliveryId,
    public readonly array $events = [],
  ) {}

}
