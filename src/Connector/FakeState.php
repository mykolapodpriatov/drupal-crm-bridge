<?php

declare(strict_types=1);

namespace Drupal\crm_bridge\Connector;

/**
 * The in-memory CRM behind the fake connector.
 *
 * This is a service rather than static state so that a kernel test can reach
 * it through the container, seed it, make it misbehave, and read it back for
 * assertions, all without the plugin knowing it is being tested.
 *
 * It is not a convenience. Every correctness claim this module makes is
 * demonstrated against it, so it has to be able to fail the way real CRMs
 * fail: redelivering webhooks, returning 429 mid-batch, reporting coarse
 * timestamps, and rejecting a write for a validation rule that lives in the
 * customer's account rather than in our configuration.
 */
class FakeState {

  /**
   * Records keyed by object name and then remote ID.
   *
   * @var array<string, array<string, array{fields: array<string, mixed>, updated: int, version: int, deleted: bool}>>
   */
  private array $records = [];

  /**
   * Schemas keyed by object name.
   *
   * @var array<string, \Drupal\crm_bridge\Connector\RemoteSchema>
   */
  private array $schemas = [];

  /**
   * Idempotency keys already honoured, keyed by key.
   *
   * @var array<string, \Drupal\crm_bridge\Connector\WriteResult>
   */
  private array $idempotency = [];

  /**
   * Queued webhook deliveries.
   *
   * @var list<array{id: string, event: \Drupal\crm_bridge\Connector\WebhookEvent}>
   */
  private array $deliveries = [];

  /**
   * Call counters keyed by operation.
   *
   * @var array<string, int>
   */
  private array $calls = [];

  /**
   * The capability set the plugin reports.
   */
  private ConnectorCapabilities $capabilities;

  /**
   * The injected faults.
   *
   * @var array<string, mixed>
   */
  private array $faults = [];

  /**
   * The peer's current time, as a Unix timestamp.
   */
  private int $now = 0;

  /**
   * The next generated record ID.
   */
  private int $nextId = 0;

  /**
   * The next delivery sequence number.
   */
  private int $nextDelivery = 0;

  /**
   * Constructs the state with a well-behaved default peer.
   */
  public function __construct() {
    $this->capabilities = new ConnectorCapabilities();
    $this->now = 1767225600;
  }

  /**
   * Replaces the capability set.
   *
   * @param \Drupal\crm_bridge\Connector\ConnectorCapabilities $capabilities
   *   The capabilities to report.
   */
  public function setCapabilities(ConnectorCapabilities $capabilities): void {
    $this->capabilities = $capabilities;
  }

  /**
   * The capability set.
   *
   * @return \Drupal\crm_bridge\Connector\ConnectorCapabilities
   *   The capabilities.
   */
  public function capabilities(): ConnectorCapabilities {
    return $this->capabilities;
  }

  /**
   * Declares a remote object's schema.
   *
   * @param \Drupal\crm_bridge\Connector\RemoteSchema $schema
   *   The schema.
   */
  public function setSchema(RemoteSchema $schema): void {
    $this->schemas[$schema->object] = $schema;
  }

  /**
   * Returns a schema.
   *
   * @param string $object
   *   The object name.
   *
   * @return \Drupal\crm_bridge\Connector\RemoteSchema|null
   *   The schema, or NULL when the object is unknown.
   */
  public function schema(string $object): ?RemoteSchema {
    return $this->schemas[$object] ?? NULL;
  }

  /**
   * Sets the injected faults.
   *
   * Recognised keys: rate_limit_every, retry_after, server_error_every,
   * reject_field, reject_value, duplicate_webhooks, notification_only,
   * page_size.
   *
   * @param array<string, mixed> $faults
   *   The faults.
   */
  public function setFaults(array $faults): void {
    $this->faults = $faults;
  }

  /**
   * Reads one fault setting.
   *
   * @param string $name
   *   The setting name.
   * @param mixed $default
   *   What to return when unset.
   *
   * @return mixed
   *   The value.
   */
  public function fault(string $name, mixed $default = NULL): mixed {
    return $this->faults[$name] ?? $default;
  }

  /**
   * The peer's clock.
   *
   * @return int
   *   A Unix timestamp.
   */
  public function now(): int {
    return $this->now;
  }

  /**
   * Moves the peer's clock.
   *
   * @param int $seconds
   *   How far forward.
   */
  public function advance(int $seconds): void {
    $this->now += $seconds;
  }

  /**
   * The peer's modification stamp, at whatever granularity it keeps.
   *
   * @return int
   *   A Unix timestamp.
   */
  public function stamp(): int {
    $granularity = $this->capabilities->timestampGranularitySeconds;
    if ($granularity <= 0) {
      return $this->now;
    }
    return intdiv($this->now, $granularity) * $granularity;
  }

  /**
   * Counts an API call and returns the running total.
   *
   * @param string $operation
   *   The operation name.
   *
   * @return int
   *   How many times it has been called.
   */
  public function countCall(string $operation): int {
    $this->calls[$operation] = ($this->calls[$operation] ?? 0) + 1;
    return $this->calls[$operation];
  }

  /**
   * How many times an operation was attempted.
   *
   * @param string $operation
   *   The operation name.
   *
   * @return int
   *   The count, including attempts failed on purpose.
   */
  public function calls(string $operation): int {
    return $this->calls[$operation] ?? 0;
  }

  /**
   * Writes a record as the API would.
   *
   * @param string $object
   *   The object name.
   * @param string $remoteId
   *   The record ID, empty to create.
   * @param array<string, mixed> $fields
   *   The fields to merge in.
   *
   * @return array{id: string, created: bool, version: int, updated: int}
   *   What the write produced.
   */
  public function write(string $object, string $remoteId, array $fields): array {
    $created = FALSE;
    if ($remoteId === '') {
      $this->nextId++;
      $remoteId = 'fake-' . $this->nextId;
      $created = TRUE;
    }
    if (!isset($this->records[$object][$remoteId])) {
      $created = TRUE;
      $this->records[$object][$remoteId] = [
        'fields' => [],
        'updated' => 0,
        'version' => 0,
        'deleted' => FALSE,
      ];
    }

    $record = &$this->records[$object][$remoteId];
    $record['deleted'] = FALSE;
    $record['fields'] = $fields + $record['fields'];
    foreach ($fields as $name => $value) {
      $record['fields'][$name] = $value;
    }
    $record['updated'] = $this->stamp();
    $record['version']++;

    return [
      'id' => $remoteId,
      'created' => $created,
      'version' => $record['version'],
      'updated' => $record['updated'],
    ];
  }

  /**
   * Removes a record, honouring the soft-delete capability.
   *
   * @param string $object
   *   The object name.
   * @param string $remoteId
   *   The record ID.
   *
   * @return bool
   *   TRUE when a record was there to remove.
   */
  public function remove(string $object, string $remoteId): bool {
    if (!isset($this->records[$object][$remoteId])) {
      return FALSE;
    }
    if ($this->capabilities->softDelete) {
      $this->records[$object][$remoteId]['deleted'] = TRUE;
      $this->records[$object][$remoteId]['updated'] = $this->stamp();
    }
    else {
      unset($this->records[$object][$remoteId]);
    }
    return TRUE;
  }

  /**
   * Reads one live record.
   *
   * @param string $object
   *   The object name.
   * @param string $remoteId
   *   The record ID.
   *
   * @return \Drupal\crm_bridge\Connector\RemoteRecord|null
   *   The record, or NULL when it is absent or deleted.
   */
  public function record(string $object, string $remoteId): ?RemoteRecord {
    $raw = $this->records[$object][$remoteId] ?? NULL;
    if ($raw === NULL || $raw['deleted']) {
      return NULL;
    }
    return new RemoteRecord(
      $remoteId,
      $raw['fields'],
      $raw['updated'],
      $this->capabilities->etags ? (string) $raw['version'] : '',
    );
  }

  /**
   * Every live record of an object, sorted by modification time then ID.
   *
   * @param string $object
   *   The object name.
   * @param int $since
   *   Only records modified at or after this timestamp.
   *
   * @return list<\Drupal\crm_bridge\Connector\RemoteRecord>
   *   The records.
   */
  public function records(string $object, int $since = 0): array {
    $out = [];
    foreach ($this->records[$object] ?? [] as $id => $raw) {
      if ($raw['deleted'] || $raw['updated'] < $since) {
        continue;
      }
      $out[] = $this->record($object, (string) $id);
    }
    $out = array_values(array_filter($out));
    usort($out, static function (RemoteRecord $a, RemoteRecord $b): int {
      return [$a->updatedAt, $a->remoteId] <=> [$b->updatedAt, $b->remoteId];
    });
    return $out;
  }

  /**
   * Remote IDs of records deleted at or after a timestamp.
   *
   * Always empty on a peer that hard deletes, and that is the point rather
   * than a gap: an absence there is indistinguishable from a permission
   * change, and guessing is how a sync deletes live data.
   *
   * @param string $object
   *   The object name.
   * @param int $since
   *   Only deletions at or after this timestamp.
   *
   * @return list<string>
   *   The remote IDs.
   */
  public function deletedIds(string $object, int $since = 0): array {
    if (!$this->capabilities->softDelete) {
      return [];
    }
    $out = [];
    foreach ($this->records[$object] ?? [] as $id => $raw) {
      if ($raw['deleted'] && $raw['updated'] >= $since) {
        $out[] = (string) $id;
      }
    }
    sort($out);
    return $out;
  }

  /**
   * How many live records an object has.
   *
   * @param string $object
   *   The object name.
   *
   * @return int
   *   The count.
   */
  public function count(string $object): int {
    return count($this->records($object));
  }

  /**
   * Remembers what an idempotency key produced.
   *
   * @param string $key
   *   The key.
   * @param \Drupal\crm_bridge\Connector\WriteResult $result
   *   The result.
   */
  public function rememberKey(string $key, WriteResult $result): void {
    $this->idempotency[$key] = $result;
  }

  /**
   * Looks up a previously honoured idempotency key.
   *
   * @param string $key
   *   The key.
   *
   * @return \Drupal\crm_bridge\Connector\WriteResult|null
   *   The result, or NULL.
   */
  public function rememberedKey(string $key): ?WriteResult {
    return $this->idempotency[$key] ?? NULL;
  }

  /**
   * Queues a webhook delivery.
   *
   * @param \Drupal\crm_bridge\Connector\WebhookEvent $event
   *   The event.
   */
  public function queueDelivery(WebhookEvent $event): void {
    if (!$this->capabilities->webhooks) {
      return;
    }
    if ($this->fault('notification_only', FALSE)) {
      $event = new WebhookEvent($event->object, $event->remoteId, $event->deleted);
    }

    $this->nextDelivery++;
    // A redelivery carries the same ID. That is what makes it a redelivery
    // rather than a second event, and dedupe has to key on it.
    $id = 'fake-d' . $this->nextDelivery;
    $copies = $this->fault('duplicate_webhooks', FALSE) ? 2 : 1;
    for ($i = 0; $i < $copies; $i++) {
      $this->deliveries[] = ['id' => $id, 'event' => $event];
    }
  }

  /**
   * Takes every queued delivery.
   *
   * @return list<array{id: string, event: \Drupal\crm_bridge\Connector\WebhookEvent}>
   *   The deliveries.
   */
  public function drainDeliveries(): array {
    $out = $this->deliveries;
    $this->deliveries = [];
    return $out;
  }

  /**
   * How many deliveries are queued.
   *
   * @return int
   *   The count.
   */
  public function pendingDeliveries(): int {
    return count($this->deliveries);
  }

}
