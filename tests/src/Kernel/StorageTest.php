<?php

declare(strict_types=1);

namespace Drupal\Tests\crm_bridge\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\crm_bridge\Storage\DeliveryStorage;
use Drupal\crm_bridge\Storage\Link;
use Drupal\crm_bridge\Storage\LinkConflictException;
use Drupal\crm_bridge\Storage\LinkStorage;
use Drupal\crm_bridge\Storage\OriginStorage;
use Drupal\crm_bridge\Storage\QueueItem;
use Drupal\crm_bridge\Storage\QueueItemStorage;

/**
 * Tests the tables the sync restarts from.
 *
 * @group crm_bridge
 */
class StorageTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'crm_bridge'];

  /**
   * The link storage.
   */
  protected LinkStorage $links;

  /**
   * The origin storage.
   */
  protected OriginStorage $origins;

  /**
   * The delivery storage.
   */
  protected DeliveryStorage $deliveries;

  /**
   * The queue storage.
   */
  protected QueueItemStorage $queues;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installSchema('crm_bridge', [
      'crm_bridge_link',
      'crm_bridge_origin',
      'crm_bridge_delivery',
      'crm_bridge_dlq',
      'crm_bridge_review',
    ]);

    $this->links = $this->container->get('crm_bridge.link_storage');
    $this->origins = $this->container->get('crm_bridge.origin_storage');
    $this->deliveries = $this->container->get('crm_bridge.delivery_storage');
    $this->queues = $this->container->get('crm_bridge.queue_item_storage');
  }

  /**
   * Builds a link.
   *
   * @param string $entityId
   *   The Drupal entity ID.
   * @param string $remoteId
   *   The CRM record identifier.
   *
   * @return \Drupal\crm_bridge\Storage\Link
   *   The link.
   */
  private function link(string $entityId = '1', string $remoteId = 'hs-1'): Link {
    return new Link(
      'user_contact',
      'user',
      $entityId,
      'hubspot',
      $remoteId,
      'local-hash',
      'remote-hash',
      ['mail' => 'ann@example.com'],
      ['email' => 'ann@example.com'],
      1767225600,
    );
  }

  /**
   * A link is reachable from either side.
   */
  public function testLinkIsReachableFromBothSides(): void {
    $this->links->save($this->link());

    $fromEntity = $this->links->findByEntity('user_contact', 'user', '1');
    $fromRemote = $this->links->findByRemote('user_contact', 'hubspot', 'hs-1');

    $this->assertNotNull($fromEntity);
    $this->assertNotNull($fromRemote);
    $this->assertSame('hs-1', $fromEntity->remoteId);
    $this->assertSame('1', $fromRemote->entityId);
  }

  /**
   * The snapshot values survive the round trip.
   *
   * They are what makes field-level conflict resolution possible: merging two
   * edits is a three-way merge, and a digest alone can say a record changed
   * but never which field.
   */
  public function testSnapshotValuesRoundTrip(): void {
    $this->links->save($this->link());

    $loaded = $this->links->findByEntity('user_contact', 'user', '1');
    $this->assertNotNull($loaded);
    $this->assertSame(['mail' => 'ann@example.com'], $loaded->localValues);
    $this->assertSame(['email' => 'ann@example.com'], $loaded->remoteValues);
    $this->assertSame('local-hash', $loaded->localHash);
    $this->assertSame(1767225600, $loaded->syncedAt);
  }

  /**
   * Saving the same pair twice updates rather than duplicating.
   */
  public function testSavingTwiceUpdatesOneRow(): void {
    $this->links->save($this->link());
    $this->links->save($this->link()->withSyncState('h2', 'r2', ['mail' => 'new@example.com'], [], 2000));

    $this->assertSame(1, $this->links->count('user_contact'));
    $loaded = $this->links->findByEntity('user_contact', 'user', '1');
    $this->assertNotNull($loaded);
    $this->assertSame('h2', $loaded->localHash);
    $this->assertSame(['mail' => 'new@example.com'], $loaded->localValues);
  }

  /**
   * A CRM record already linked elsewhere is a conflict, not an overwrite.
   *
   * Overwriting would leave two Drupal entities pointing at one record, with
   * one of them silently unsynced and nothing anywhere reporting it.
   */
  public function testLinkingOneRecordToTwoEntitiesIsRejected(): void {
    $this->links->save($this->link('1', 'hs-1'));

    $this->expectException(LinkConflictException::class);
    $this->links->save($this->link('2', 'hs-1'));
  }

  /**
   * Links are scoped per mapping.
   */
  public function testLinksAreScopedPerMapping(): void {
    $this->links->save($this->link());
    $this->links->save(new Link('other_mapping', 'user', '1', 'hubspot', 'hs-9'));

    $this->assertSame(1, $this->links->count('user_contact'));
    $this->assertSame(1, $this->links->count('other_mapping'));

    $other = $this->links->findByEntity('other_mapping', 'user', '1');
    $this->assertNotNull($other);
    $this->assertSame('hs-9', $other->remoteId);
  }

  /**
   * Deleting removes exactly one link.
   */
  public function testDeletingOneLink(): void {
    $this->links->save($this->link('1', 'hs-1'));
    $this->links->save($this->link('2', 'hs-2'));

    $this->assertTrue($this->links->delete('user_contact', 'user', '1'));
    $this->assertNull($this->links->findByEntity('user_contact', 'user', '1'));
    $this->assertNotNull($this->links->findByEntity('user_contact', 'user', '2'));
    $this->assertFalse($this->links->delete('user_contact', 'user', '1'));
  }

  /**
   * The audit walks stale links oldest first.
   */
  public function testStaleLinksComeBackOldestFirst(): void {
    foreach ([300, 100, 200] as $i => $synced) {
      $this->links->save(new Link(
        'user_contact', 'user', (string) $i, 'hubspot', 'hs-' . $i,
        '', '', [], [], $synced,
      ));
    }

    $stale = $this->links->findStale('user_contact', 250);
    $this->assertCount(2, $stale);
    $this->assertSame(100, $stale[0]->syncedAt);
    $this->assertSame(200, $stale[1]->syncedAt);
  }

  /**
   * A row whose stored values cannot be read must not take the sync down.
   *
   * An unreadable base means the merge has no base, which the conflict
   * resolver already handles by refusing to guess.
   */
  public function testUnreadableStoredValuesDegradeToNoBase(): void {
    $this->links->save($this->link());
    $this->container->get('database')->update('crm_bridge_link')
      ->fields(['local_values' => 'not json at all'])
      ->execute();

    $loaded = $this->links->findByEntity('user_contact', 'user', '1');
    $this->assertNotNull($loaded);
    $this->assertSame([], $loaded->localValues);
  }

  /**
   * Our own write is recognised, once.
   */
  public function testOriginEntriesAreSingleUse(): void {
    $tag = OriginStorage::tag('hubspot', 'contacts', 'hs-1', 'payload-hash');
    $this->origins->record('user_contact', $tag, 300);

    $this->assertTrue($this->origins->claim($tag), 'Our own write was not recognised.');
    $this->assertFalse($this->origins->claim($tag), 'The same entry was claimed twice.');
  }

  /**
   * A different payload for the same record is not our echo.
   */
  public function testDifferentPayloadIsNotAnEcho(): void {
    $this->origins->record(
      'user_contact',
      OriginStorage::tag('hubspot', 'contacts', 'hs-1', 'payload-a'),
      300,
    );

    $this->assertFalse(
      $this->origins->claim(OriginStorage::tag('hubspot', 'contacts', 'hs-1', 'payload-b')),
      'A payload we never wrote was suppressed as our own.',
    );
  }

  /**
   * An expired entry stops suppressing even before it is swept.
   */
  public function testExpiredOriginEntriesStopSuppressing(): void {
    $tag = OriginStorage::tag('hubspot', 'contacts', 'hs-1', 'payload-hash');
    $this->origins->record('user_contact', $tag, -1);

    $this->assertFalse($this->origins->claim($tag));
    $this->assertSame(0, $this->origins->countLive());
  }

  /**
   * The touched marker answers the near-miss question in one lookup.
   */
  public function testTouchedRecentlyIsSeparateFromTheClaim(): void {
    $this->assertFalse($this->origins->touchedRecently('hubspot', 'contacts', 'hs-1'));

    $this->origins->markTouched('user_contact', 'hubspot', 'contacts', 'hs-1', 300);
    $this->assertTrue($this->origins->touchedRecently('hubspot', 'contacts', 'hs-1'));

    // Claiming a payload must not consume the marker, or the second inbound
    // event for the same record would look like a first contact.
    $tag = OriginStorage::tag('hubspot', 'contacts', 'hs-1', 'payload-hash');
    $this->origins->record('user_contact', $tag, 300);
    $this->origins->claim($tag);
    $this->assertTrue($this->origins->touchedRecently('hubspot', 'contacts', 'hs-1'));
  }

  /**
   * The sweep drops expired entries and keeps live ones.
   */
  public function testOriginSweep(): void {
    $this->origins->record('user_contact', 'live-tag', 300);
    $this->origins->record('user_contact', 'dead-tag', -1);

    $this->assertSame(1, $this->origins->sweep());
    $this->assertTrue($this->origins->claim('live-tag'));
  }

  /**
   * A redelivery is recognised, and identifiers are scoped per connector.
   */
  public function testDeliveryDeduplication(): void {
    $this->assertTrue($this->deliveries->claim('hubspot', 'd-1', 3600));
    $this->assertFalse($this->deliveries->claim('hubspot', 'd-1', 3600));

    // Two CRMs numbering their deliveries from one must not collide, or the
    // collision silently drops real changes.
    $this->assertTrue($this->deliveries->claim('twenty', 'd-1', 3600));
  }

  /**
   * The delivery sweep keeps the table small.
   */
  public function testDeliverySweep(): void {
    $this->deliveries->claim('hubspot', 'live', 3600);
    $this->deliveries->claim('hubspot', 'dead', -1);

    $this->assertSame(2, $this->deliveries->count());
    $this->assertSame(1, $this->deliveries->sweep());
    $this->assertSame(1, $this->deliveries->count());
  }

  /**
   * Queue items round-trip with their payload.
   */
  public function testQueueItemsRoundTrip(): void {
    $id = $this->queues->add(QueueItemStorage::DLQ, new QueueItem(
      'user_contact',
      'The CRM rejected the email address.',
      'user',
      '1',
      'hs-1',
      6,
      1000,
      ['field' => 'mail', 'value' => 'blocked@example.com'],
    ));

    $loaded = $this->queues->load(QueueItemStorage::DLQ, $id);
    $this->assertNotNull($loaded);
    $this->assertSame('user_contact', $loaded->mapping);
    $this->assertSame(6, $loaded->attempts);
    $this->assertSame('blocked@example.com', $loaded->payload['value']);
  }

  /**
   * The two queues are separate tables with one implementation.
   */
  public function testTheTwoQueuesAreIndependent(): void {
    $this->queues->add(QueueItemStorage::DLQ, new QueueItem('user_contact', 'failed'));
    $this->queues->add(QueueItemStorage::REVIEW, new QueueItem('user_contact', 'ambiguous'));

    $this->assertSame(1, $this->queues->count(QueueItemStorage::DLQ));
    $this->assertSame(1, $this->queues->count(QueueItemStorage::REVIEW));

    $dlq = $this->queues->list(QueueItemStorage::DLQ);
    $this->assertSame('failed', $dlq[0]->reason);
  }

  /**
   * Items come back oldest first, so a queue reads as a queue.
   */
  public function testQueueItemsListOldestFirst(): void {
    foreach ([300, 100, 200] as $created) {
      $this->queues->add(QueueItemStorage::DLQ, new QueueItem(
        'user_contact', 'reason-' . $created, '', '', '', 0, $created,
      ));
    }

    $items = $this->queues->list(QueueItemStorage::DLQ);
    $this->assertSame(['reason-100', 'reason-200', 'reason-300'], array_map(
      static fn (QueueItem $i): string => $i->reason,
      $items,
    ));
  }

  /**
   * A mapping's failures do not outlive the mapping.
   */
  public function testDeletingByMapping(): void {
    $this->queues->add(QueueItemStorage::DLQ, new QueueItem('user_contact', 'a'));
    $this->queues->add(QueueItemStorage::DLQ, new QueueItem('other', 'b'));

    $this->assertSame(1, $this->queues->deleteByMapping(QueueItemStorage::DLQ, 'user_contact'));
    $this->assertSame(1, $this->queues->count(QueueItemStorage::DLQ));
  }

  /**
   * An over-long reason is truncated rather than rejected.
   *
   * The reason must never be the thing that stops a failure being recorded.
   */
  public function testAnOverLongReasonIsTruncated(): void {
    $id = $this->queues->add(QueueItemStorage::DLQ, new QueueItem(
      'user_contact',
      str_repeat('x', 500),
    ));

    $loaded = $this->queues->load(QueueItemStorage::DLQ, $id);
    $this->assertNotNull($loaded);
    $this->assertSame(255, mb_strlen($loaded->reason));
  }

  /**
   * The table name is a parameter, so it is checked.
   */
  public function testAnUnknownQueueTableIsRejected(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->queues->count('users');
  }

}
