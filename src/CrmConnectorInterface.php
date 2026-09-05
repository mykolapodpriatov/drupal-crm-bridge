<?php

declare(strict_types=1);

namespace Drupal\crm_bridge;

use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\crm_bridge\Connector\ConnectorCapabilities;
use Drupal\crm_bridge\Connector\RemotePage;
use Drupal\crm_bridge\Connector\RemoteRecord;
use Drupal\crm_bridge\Connector\RemoteSchema;
use Drupal\crm_bridge\Connector\WebhookVerification;
use Drupal\crm_bridge\Connector\WriteResult;
use Symfony\Component\HttpFoundation\Request;

/**
 * The contract every CRM connector satisfies.
 *
 * Nothing above this interface knows which CRM is on the other side. It knows
 * what the peer can do, from capabilities(), and adapts.
 *
 * Implementations may be called from queue workers, so they must not assume a
 * request context, and they must not rate-limit internally: the module's own
 * limiter sits above them and needs to see every call.
 */
interface CrmConnectorInterface extends PluginInspectionInterface {

  /**
   * What this connector's peer can do.
   *
   * @return \Drupal\crm_bridge\Connector\ConnectorCapabilities
   *   The capability set.
   */
  public function capabilities(): ConnectorCapabilities;

  /**
   * Describes a remote object.
   *
   * @param string $object
   *   The remote object name.
   *
   * @return \Drupal\crm_bridge\Connector\RemoteSchema
   *   The schema.
   *
   * @throws \Drupal\crm_bridge\Connector\ConnectorException
   *   With FailureKind::NotFound when the object does not exist, so that
   *   mapping validation fails loudly instead of validating against nothing.
   */
  public function describe(string $object): RemoteSchema;

  /**
   * Reads records modified at or after a timestamp.
   *
   * @param string $object
   *   The remote object name.
   * @param int $since
   *   A Unix timestamp.
   * @param string $cursor
   *   The cursor from a previous page, or an empty string to start.
   *
   * @return \Drupal\crm_bridge\Connector\RemotePage
   *   The page.
   *
   * @throws \Drupal\crm_bridge\Connector\ConnectorException
   *   On any failure.
   */
  public function listChanged(string $object, int $since, string $cursor = ''): RemotePage;

  /**
   * Fetches one record.
   *
   * @param string $object
   *   The remote object name.
   * @param string $remoteId
   *   The record identifier.
   *
   * @return \Drupal\crm_bridge\Connector\RemoteRecord
   *   The record.
   *
   * @throws \Drupal\crm_bridge\Connector\ConnectorException
   *   With FailureKind::NotFound when the record does not exist.
   */
  public function get(string $object, string $remoteId): RemoteRecord;

  /**
   * Creates or updates a record.
   *
   * @param string $object
   *   The remote object name.
   * @param \Drupal\crm_bridge\Connector\RemoteRecord $record
   *   The record to write. An empty remote ID means create.
   * @param string $idempotencyKey
   *   The module's key for this write. The same key with the same payload
   *   must not produce a second record, whether the peer enforces that or the
   *   connector does.
   *
   * @return \Drupal\crm_bridge\Connector\WriteResult
   *   What the write produced.
   *
   * @throws \Drupal\crm_bridge\Connector\ConnectorException
   *   On any failure.
   */
  public function upsert(string $object, RemoteRecord $record, string $idempotencyKey): WriteResult;

  /**
   * Removes a record.
   *
   * Deleting a record that is already gone must succeed. A retry after a
   * delete that already landed is the normal case, not an exception, and a
   * connector that errors on it turns every delete into a coin flip.
   *
   * @param string $object
   *   The remote object name.
   * @param string $remoteId
   *   The record identifier.
   * @param string $idempotencyKey
   *   The module's key for this write.
   *
   * @throws \Drupal\crm_bridge\Connector\ConnectorException
   *   On any failure other than the record being absent.
   */
  public function delete(string $object, string $remoteId, string $idempotencyKey): void;

  /**
   * Authenticates an inbound delivery and parses it.
   *
   * Implementations must authenticate before looking at the body, compare
   * signatures in constant time, and reject a delivery whose timestamp is
   * outside the peer's replay window.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The inbound request.
   *
   * @return \Drupal\crm_bridge\Connector\WebhookVerification
   *   The delivery identifier and its events.
   *
   * @throws \Drupal\crm_bridge\Connector\ConnectorException
   *   With FailureKind::Auth when the delivery is not authentic, and
   *   FailureKind::Permanent when it is authentic but unparseable. Those are
   *   different problems and page different people.
   */
  public function verifyWebhook(Request $request): WebhookVerification;

}
