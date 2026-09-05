<?php

declare(strict_types=1);

namespace Drupal\Tests\crm_bridge\Kernel;

use Drupal\crm_bridge\Connector\ConnectorCapabilities;
use Drupal\crm_bridge\Connector\ConnectorException;
use Drupal\crm_bridge\Connector\FailureKind;
use Drupal\crm_bridge\Connector\FakeState;
use Drupal\crm_bridge\Connector\RemoteField;
use Drupal\crm_bridge\Connector\RemoteRecord;
use Drupal\crm_bridge\Connector\RemoteSchema;
use Drupal\crm_bridge\CrmConnectorInterface;
use Drupal\crm_bridge\Plugin\CrmConnector\FakeConnector;
use Symfony\Component\HttpFoundation\Request;

/**
 * Runs the connector conformance suite against the fake, and its faults.
 *
 * @group crm_bridge
 */
class FakeConnectorTest extends ConnectorConformanceTestBase {

  /**
   * The in-memory CRM.
   */
  protected FakeState $state;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->state = $this->container->get('crm_bridge.fake_state');
    $this->state->setCapabilities(new ConnectorCapabilities(
      nativeIdempotency: TRUE,
      webhooks: TRUE,
      softDelete: TRUE,
      etags: TRUE,
    ));
    $this->state->setSchema(new RemoteSchema('contacts', [
      'email' => new RemoteField('email', 'string', required: TRUE),
      'firstname' => new RemoteField('firstname'),
      'createdate' => new RemoteField('createdate', 'datetime', readOnly: TRUE),
    ]));
  }

  /**
   * {@inheritdoc}
   */
  protected function connector(): CrmConnectorInterface {
    return $this->container->get('plugin.manager.crm_connector')->connector('fake');
  }

  /**
   * {@inheritdoc}
   */
  protected function object(): string {
    return 'contacts';
  }

  /**
   * {@inheritdoc}
   */
  protected function field(): string {
    return 'email';
  }

  /**
   * {@inheritdoc}
   */
  protected function signedDelivery(): ?Request {
    return $this->delivery(
      ['events' => [['object' => 'contacts', 'remote_id' => 'fake-1']]],
      $this->state->now(),
    );
  }

  /**
   * Builds a signed delivery request.
   *
   * @param array<string, mixed> $payload
   *   The body to send.
   * @param int $timestamp
   *   The timestamp to sign with.
   * @param string $secret
   *   The signing secret.
   *
   * @return \Symfony\Component\HttpFoundation\Request
   *   The request.
   */
  private function delivery(array $payload, int $timestamp, string $secret = 'fake-secret'): Request {
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $request = Request::create('/crm-bridge/webhook/fake', 'POST', [], [], [], [], $body);
    $request->headers->set(FakeConnector::HEADER_TIMESTAMP, (string) $timestamp);
    $request->headers->set(FakeConnector::HEADER_SIGNATURE, FakeConnector::sign($secret, $timestamp, $body));
    $request->headers->set(FakeConnector::HEADER_DELIVERY, 'fake-d1');
    return $request;
  }

  /**
   * The plugin is discoverable and reports its own metadata.
   */
  public function testThePluginIsDiscoverable(): void {
    $definitions = $this->container->get('plugin.manager.crm_connector')->getDefinitions();

    $this->assertArrayHasKey('fake', $definitions);
    $this->assertSame('fake', $this->connector()->getPluginId());
  }

  /**
   * A rate limit is classified and carries the peer's retry hint.
   */
  public function testRateLimitIsClassified(): void {
    $this->state->setFaults(['rate_limit_every' => 2, 'retry_after' => 7]);

    $this->connector()->listChanged('contacts', 0);
    try {
      $this->connector()->listChanged('contacts', 0);
      $this->fail('The second call was not rate limited.');
    }
    catch (ConnectorException $e) {
      $this->assertSame(FailureKind::RateLimited, $e->kind);
      $this->assertSame(7, $e->retryAfterSeconds);
      $this->assertTrue($e->isRetryable());
    }
  }

  /**
   * A validation rejection is permanent and a 5xx is not.
   *
   * Retrying a rejection only burns quota until the attempt budget runs out,
   * and the reason lives in the customer's CRM settings rather than in our
   * configuration, so it has to read clearly in the dead-letter queue.
   */
  public function testPermanentAndTransientAreDistinguished(): void {
    $this->state->setFaults(['server_error_every' => 1]);
    try {
      $this->connector()->get('contacts', 'x');
      $this->fail('The injected 503 did not fire.');
    }
    catch (ConnectorException $e) {
      $this->assertSame(FailureKind::Transient, $e->kind);
      $this->assertTrue($e->isRetryable());
    }

    $this->state->setFaults(['reject_field' => 'email', 'reject_value' => 'blocked@example.com']);
    try {
      $this->connector()->upsert(
        'contacts',
        new RemoteRecord('', ['email' => 'blocked@example.com']),
        'k',
      );
      $this->fail('The validation rule did not fire.');
    }
    catch (ConnectorException $e) {
      $this->assertSame(FailureKind::Permanent, $e->kind);
      $this->assertFalse($e->isRetryable());
    }
  }

  /**
   * An unclassified failure is retryable.
   *
   * Giving up on something we do not understand loses data; retrying it costs
   * a request.
   */
  public function testUnknownFailuresAreRetryable(): void {
    $this->assertTrue((new ConnectorException('mystery'))->isRetryable());
  }

  /**
   * A redelivery carries the same delivery ID.
   *
   * Two different IDs would be two events, and dropping one of those would
   * drop a real change, so dedupe has to key on the ID rather than on content.
   */
  public function testRedeliveriesShareOneDeliveryId(): void {
    $this->state->setFaults(['duplicate_webhooks' => TRUE]);
    $this->connector()->upsert('contacts', new RemoteRecord('', ['email' => 'a@b.c']), 'k');

    $deliveries = $this->state->drainDeliveries();
    $this->assertCount(2, $deliveries);
    $this->assertSame($deliveries[0]['id'], $deliveries[1]['id']);
  }

  /**
   * A notification-only delivery still says which record changed.
   */
  public function testNotificationOnlyDeliveriesCarryNoBody(): void {
    $this->state->setFaults(['notification_only' => TRUE]);
    $result = $this->connector()->upsert('contacts', new RemoteRecord('', ['email' => 'a@b.c']), 'k');

    $deliveries = $this->state->drainDeliveries();
    $this->assertCount(1, $deliveries);
    $this->assertFalse($deliveries[0]['event']->isHydrated());
    $this->assertSame($result->remoteId, $deliveries[0]['event']->remoteId);
  }

  /**
   * A genuine signature over a stale timestamp is a replay.
   */
  public function testStaleDeliveriesAreRejected(): void {
    $request = $this->signedDelivery();
    $this->state->advance(FakeConnector::SIGNATURE_WINDOW + 60);

    try {
      $this->connector()->verifyWebhook($request);
      $this->fail('A stale delivery was accepted.');
    }
    catch (ConnectorException $e) {
      $this->assertSame(FailureKind::Auth, $e->kind);
    }
  }

  /**
   * A delivery signed with somebody else's secret is rejected.
   */
  public function testForeignSignaturesAreRejected(): void {
    $request = $this->delivery(
      ['events' => []],
      $this->state->now(),
      'a-different-secret',
    );

    try {
      $this->connector()->verifyWebhook($request);
      $this->fail('A delivery signed with another secret was accepted.');
    }
    catch (ConnectorException $e) {
      $this->assertSame(FailureKind::Auth, $e->kind);
    }
  }

  /**
   * An authentic but unparseable body is the peer's bug, not an attack.
   */
  public function testMalformedBodyIsPermanentNotAuth(): void {
    $timestamp = $this->state->now();
    $body = '{not json';
    $request = Request::create('/crm-bridge/webhook/fake', 'POST', [], [], [], [], $body);
    $request->headers->set(FakeConnector::HEADER_TIMESTAMP, (string) $timestamp);
    $request->headers->set(FakeConnector::HEADER_SIGNATURE, FakeConnector::sign('fake-secret', $timestamp, $body));

    try {
      $this->connector()->verifyWebhook($request);
      $this->fail('A malformed body was accepted.');
    }
    catch (ConnectorException $e) {
      $this->assertSame(FailureKind::Permanent, $e->kind);
    }
  }

  /**
   * The connector signs with the secret from the credential store.
   */
  public function testTheSigningSecretComesFromTheCredentialStore(): void {
    $this->container->get('crm_bridge.credentials')
      ->set('fake', [FakeConnector::SECRET_NAME => 'rotated-secret']);

    $rotated = $this->delivery(['events' => []], $this->state->now(), 'rotated-secret');
    $verified = $this->connector()->verifyWebhook($rotated);
    $this->assertSame('fake-d1', $verified->deliveryId);

    $old = $this->delivery(['events' => []], $this->state->now(), 'fake-secret');
    $this->expectException(ConnectorException::class);
    $this->connector()->verifyWebhook($old);
  }

  /**
   * Coarse timestamps are reported coarsely.
   *
   * This is what makes a naive watermark skip records, so the fake has to be
   * able to do it.
   */
  public function testCoarseTimestampsAreReportedCoarsely(): void {
    $this->state->setCapabilities(new ConnectorCapabilities(timestampGranularitySeconds: 60));
    $this->state->advance(90);

    $result = $this->connector()->upsert('contacts', new RemoteRecord('', ['email' => 'a@b.c']), 'k');
    $record = $this->connector()->get('contacts', $result->remoteId);

    $this->assertSame(0, $record->updatedAt % 60, 'The timestamp was not truncated.');
  }

  /**
   * Only a soft-deleting peer can report a deletion on a poll.
   *
   * A hard-deleting one reports nothing, and that is correct rather than a
   * gap: an absence there is indistinguishable from a permission change.
   */
  public function testOnlySoftDeletesAreVisibleToPolling(): void {
    $id = $this->createRecord(1);
    $this->connector()->delete('contacts', $id, 'k');
    $this->assertSame([$id], $this->connector()->listChanged('contacts', 0)->deletedIds);

    $this->state->setCapabilities(new ConnectorCapabilities(webhooks: TRUE));
    $other = $this->createRecord(2);
    $this->connector()->delete('contacts', $other, 'k');

    // On a peer that hard deletes, a removed record and one that stopped being
    // visible to our token are the same observation, and guessing between them
    // is how a sync deletes live customer data.
    $this->assertSame([], $this->connector()->listChanged('contacts', 0)->deletedIds);
  }

  /**
   * A peer with no webhook support neither queues nor accepts deliveries.
   */
  public function testWebhooksAreRefusedWithoutSupport(): void {
    $this->state->setCapabilities(new ConnectorCapabilities());
    $this->connector()->upsert('contacts', new RemoteRecord('', ['email' => 'a@b.c']), 'k');

    $this->assertSame(0, $this->state->pendingDeliveries());
    $this->expectException(ConnectorException::class);
    $this->connector()->verifyWebhook(Request::create('/x', 'POST'));
  }

}
