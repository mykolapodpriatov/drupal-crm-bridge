<?php

declare(strict_types=1);

namespace Drupal\Tests\crm_bridge\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\crm_bridge\Connector\ConnectorException;
use Drupal\crm_bridge\Connector\FailureKind;
use Drupal\crm_bridge\Connector\RemoteRecord;
use Drupal\crm_bridge\CrmConnectorInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The bar every connector has to clear.
 *
 * It exists so that "the fake behaves like a CRM" and "the HubSpot plugin
 * behaves like a CRM" are the same claim, checked the same way. The fake runs
 * it in CI; the real plugins will run it against a developer account behind an
 * environment guard.
 *
 * Abstract, so PHPUnit does not try to run it on its own.
 */
abstract class ConnectorConformanceTestBase extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'crm_bridge'];

  /**
   * The connector under test.
   *
   * @return \Drupal\crm_bridge\CrmConnectorInterface
   *   The connector.
   */
  abstract protected function connector(): CrmConnectorInterface;

  /**
   * A remote object the connector supports.
   *
   * @return string
   *   The object name.
   */
  abstract protected function object(): string;

  /**
   * A writable field on that object.
   *
   * @return string
   *   The remote field name.
   */
  abstract protected function field(): string;

  /**
   * Builds a valid signed delivery, or NULL when webhooks are unsupported.
   *
   * @return \Symfony\Component\HttpFoundation\Request|null
   *   The request.
   */
  abstract protected function signedDelivery(): ?Request;

  /**
   * A distinct value for the writable field.
   *
   * @param int $seq
   *   A sequence number.
   *
   * @return string
   *   The value.
   */
  protected function value(int $seq): string {
    return sprintf('conformance-%d', $seq);
  }

  /**
   * Writes a record and returns its remote ID.
   *
   * @param int $seq
   *   A sequence number, used for the value and the idempotency key.
   *
   * @return string
   *   The remote ID.
   */
  protected function createRecord(int $seq = 1): string {
    return $this->connector()->upsert(
      $this->object(),
      new RemoteRecord('', [$this->field() => $this->value($seq)]),
      'conformance-' . $seq,
    )->remoteId;
  }

  /**
   * Describing a known object works, and an unknown one is a classified miss.
   */
  public function testDescribe(): void {
    $schema = $this->connector()->describe($this->object());

    $this->assertSame($this->object(), $schema->object);
    $this->assertTrue(
      $schema->has($this->field()),
      sprintf('Schema has no field "%s"; it has %s.', $this->field(), implode(', ', $schema->names())),
    );

    // An unknown object must be a classified not-found, not an empty schema,
    // or mapping validation would happily validate against nothing.
    try {
      $this->connector()->describe('definitely-not-an-object');
      $this->fail('Describing an unknown object succeeded.');
    }
    catch (ConnectorException $e) {
      $this->assertSame(FailureKind::NotFound, $e->kind);
    }
  }

  /**
   * An upsert without an ID creates, and the record reads back.
   */
  public function testUpsertCreatesAndGetReads(): void {
    $result = $this->connector()->upsert(
      $this->object(),
      new RemoteRecord('', [$this->field() => $this->value(1)]),
      'conformance-create',
    );

    $this->assertNotSame('', $result->remoteId);
    $this->assertTrue($result->created, 'Creating a record reported created = FALSE.');

    $record = $this->connector()->get($this->object(), $result->remoteId);
    $this->assertSame($result->remoteId, $record->remoteId);
    $this->assertSame($this->value(1), (string) $record->get($this->field()));
    $this->assertGreaterThan(
      0,
      $record->updatedAt,
      'A record with no modification time makes watermarks impossible.',
    );
  }

  /**
   * An upsert with an ID updates rather than creating a second record.
   */
  public function testUpsertWithAnIdUpdates(): void {
    $id = $this->createRecord(1);

    $second = $this->connector()->upsert(
      $this->object(),
      new RemoteRecord($id, [$this->field() => $this->value(2)]),
      'conformance-update',
    );

    $this->assertFalse($second->created);
    $this->assertSame($id, $second->remoteId);
    $this->assertSame(
      $this->value(2),
      (string) $this->connector()->get($this->object(), $id)->get($this->field()),
    );
  }

  /**
   * A record written a moment ago appears in a delta read.
   */
  public function testDeltaReadSeesTheWrite(): void {
    $id = $this->createRecord(1);

    $page = $this->connector()->listChanged($this->object(), 0);
    $ids = array_map(static fn (RemoteRecord $r): string => $r->remoteId, $page->records);
    $this->assertContains($id, $ids);

    // A watermark in the future must return nothing, or the watermark is
    // decorative and every poll is a full scan.
    $future = $this->connector()->listChanged($this->object(), time() + 86400);
    $this->assertSame([], $future->records);
  }

  /**
   * Paging returns every record exactly once.
   *
   * A repeat costs redundant writes. A drop loses data silently, which is
   * worse, because nothing anywhere reports it.
   */
  public function testPagingCoversEverythingExactlyOnce(): void {
    $expected = [];
    for ($i = 0; $i < 7; $i++) {
      $expected[$this->createRecord($i)] = TRUE;
    }

    $seen = [];
    $cursor = '';
    for ($page = 0; $page <= 12; $page++) {
      $result = $this->connector()->listChanged($this->object(), 0, $cursor);
      foreach ($result->records as $record) {
        $seen[$record->remoteId] = ($seen[$record->remoteId] ?? 0) + 1;
      }
      if (!$result->hasMore()) {
        break;
      }
      $cursor = $result->cursor;
      $this->assertLessThan(12, $page, 'Paging did not terminate.');
    }

    foreach (array_keys($expected) as $id) {
      $this->assertSame(1, $seen[$id] ?? 0, sprintf('Record %s was returned %d times.', $id, $seen[$id] ?? 0));
    }
  }

  /**
   * Fetching a record that does not exist is a classified miss.
   */
  public function testGetOfAnAbsentRecordIsNotFound(): void {
    try {
      $this->connector()->get($this->object(), 'definitely-not-a-real-id');
      $this->fail('Fetching an absent record succeeded.');
    }
    catch (ConnectorException $e) {
      $this->assertSame(FailureKind::NotFound, $e->kind);
    }
  }

  /**
   * Deleting twice succeeds.
   *
   * A retry after a delete that already landed is the normal case, so a
   * connector that errors on the second call turns every delete into a coin
   * flip.
   */
  public function testDeleteIsRepeatable(): void {
    $id = $this->createRecord(1);

    $this->connector()->delete($this->object(), $id, 'conformance-delete');
    try {
      $this->connector()->get($this->object(), $id);
      $this->fail('The record is still readable after being deleted.');
    }
    catch (ConnectorException $e) {
      $this->assertSame(FailureKind::NotFound, $e->kind);
    }

    $this->connector()->delete($this->object(), $id, 'conformance-delete');
    $this->addToAssertionCount(1);
  }

  /**
   * A peer claiming native idempotency honours a replayed key.
   */
  public function testIdempotencyKeyIsHonoured(): void {
    if (!$this->connector()->capabilities()->nativeIdempotency) {
      $this->markTestSkipped('This connector does not claim native idempotency.');
    }

    $record = new RemoteRecord('', [$this->field() => $this->value(1)]);
    $first = $this->connector()->upsert($this->object(), $record, 'conformance-idem');
    $second = $this->connector()->upsert($this->object(), $record, 'conformance-idem');

    $this->assertSame($first->remoteId, $second->remoteId, 'A replayed key created a second record.');
    $this->assertTrue($second->created, 'A replayed key must report the original outcome.');
  }

  /**
   * A tampered body fails verification, and fails as an auth problem.
   */
  public function testWebhookRejectsTamperedBodies(): void {
    $request = $this->signedDelivery();
    if ($request === NULL) {
      $this->markTestSkipped('This connector does not accept webhooks.');
    }

    $verified = $this->connector()->verifyWebhook($request);
    $this->assertNotSame('', $verified->deliveryId, 'A delivery with no ID cannot be deduplicated.');

    $tampered = Request::create(
      $request->getPathInfo(),
      'POST',
      [],
      [],
      [],
      [],
      $request->getContent() . ' ',
    );
    foreach ($request->headers->all() as $name => $values) {
      $tampered->headers->set($name, $values);
    }

    try {
      $this->connector()->verifyWebhook($tampered);
      $this->fail('A tampered body passed verification.');
    }
    catch (ConnectorException $e) {
      $this->assertSame(FailureKind::Auth, $e->kind);
    }
  }

}
