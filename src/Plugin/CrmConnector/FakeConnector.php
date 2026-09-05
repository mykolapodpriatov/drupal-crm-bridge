<?php

declare(strict_types=1);

namespace Drupal\crm_bridge\Plugin\CrmConnector;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\crm_bridge\Connector\ConnectorCapabilities;
use Drupal\crm_bridge\Connector\ConnectorException;
use Drupal\crm_bridge\Connector\FailureKind;
use Drupal\crm_bridge\Connector\FakeState;
use Drupal\crm_bridge\Connector\RemotePage;
use Drupal\crm_bridge\Connector\RemoteRecord;
use Drupal\crm_bridge\Connector\RemoteSchema;
use Drupal\crm_bridge\Connector\WebhookEvent;
use Drupal\crm_bridge\Connector\WebhookVerification;
use Drupal\crm_bridge\Connector\WriteResult;
use Drupal\crm_bridge\CredentialStore;
use Drupal\crm_bridge\CrmConnectorInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * An in-memory CRM that can misbehave on demand.
 *
 * @CrmConnector(
 *   id = "fake",
 *   label = @Translation("Fake CRM"),
 *   description = @Translation("An in-memory CRM used by the test suite. It is not useful on a real site.")
 * )
 *
 * Final on purpose. It is a test double, so there is no reason to subclass it,
 * and being final is what lets create() use new static() without the promise
 * that every subclass keeps this constructor signature.
 */
final class FakeConnector extends PluginBase implements CrmConnectorInterface, ContainerFactoryPluginInterface {

  /**
   * The header carrying the signature.
   */
  public const HEADER_SIGNATURE = 'X-Fake-Signature';

  /**
   * The header carrying the signed timestamp.
   */
  public const HEADER_TIMESTAMP = 'X-Fake-Timestamp';

  /**
   * The header carrying the delivery identifier.
   */
  public const HEADER_DELIVERY = 'X-Fake-Delivery';

  /**
   * How old a delivery may be before it is treated as a replay.
   */
  public const SIGNATURE_WINDOW = 300;

  /**
   * The credential name holding the webhook signing secret.
   */
  public const SECRET_NAME = 'webhook_secret';

  /**
   * Constructs the connector.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\crm_bridge\Connector\FakeState $state
   *   The in-memory CRM.
   * @param \Drupal\crm_bridge\CredentialStore $credentials
   *   The credential store.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly FakeState $state,
    protected readonly CredentialStore $credentials,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   *
   * @return static
   *   The connector.
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('crm_bridge.fake_state'),
      $container->get('crm_bridge.credentials'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function capabilities(): ConnectorCapabilities {
    return $this->state->capabilities();
  }

  /**
   * Applies the injected transport faults for one call.
   *
   * @param string $operation
   *   The operation name.
   *
   * @throws \Drupal\crm_bridge\Connector\ConnectorException
   *   When a fault fires.
   */
  private function gate(string $operation): void {
    $n = $this->state->countCall($operation);

    $rateEvery = (int) $this->state->fault('rate_limit_every', 0);
    if ($rateEvery > 0 && $n % $rateEvery === 0) {
      throw new ConnectorException(
        'Too many requests.',
        FailureKind::RateLimited,
        (int) $this->state->fault('retry_after', 0),
      );
    }

    $errorEvery = (int) $this->state->fault('server_error_every', 0);
    if ($errorEvery > 0 && $n % $errorEvery === 0) {
      throw new ConnectorException('Upstream 503.', FailureKind::Transient);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function describe(string $object): RemoteSchema {
    $this->gate('describe');

    $schema = $this->state->schema($object);
    if ($schema === NULL) {
      throw new ConnectorException(
        sprintf('No object named "%s".', $object),
        FailureKind::NotFound,
      );
    }
    return $schema;
  }

  /**
   * {@inheritdoc}
   */
  public function listChanged(string $object, int $since, string $cursor = ''): RemotePage {
    $this->gate('list');

    $records = $this->state->records($object, $since);
    $start = $cursor === '' ? 0 : (int) $cursor;
    if ($start > count($records)) {
      $start = count($records);
    }

    $pageSize = (int) $this->state->fault('page_size', 0);
    $end = count($records);
    $next = '';
    if ($pageSize > 0 && $end - $start > $pageSize) {
      $end = $start + $pageSize;
      $next = (string) $end;
    }

    return new RemotePage(
      array_values(array_slice($records, $start, $end - $start)),
      // Deletions ride along with the first page only, so the caller sees
      // each one once per poll rather than once per page.
      $start === 0 ? $this->state->deletedIds($object, $since) : [],
      $next,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function get(string $object, string $remoteId): RemoteRecord {
    $this->gate('get');

    $record = $this->state->record($object, $remoteId);
    if ($record === NULL) {
      throw new ConnectorException(
        sprintf('No %s with ID "%s".', $object, $remoteId),
        FailureKind::NotFound,
      );
    }
    return $record;
  }

  /**
   * {@inheritdoc}
   */
  public function upsert(string $object, RemoteRecord $record, string $idempotencyKey): WriteResult {
    // The idempotency check runs before the fault gate on purpose. A peer that
    // honours the key short-circuits before it can rate-limit us, which is
    // exactly the behaviour that makes retries cheap.
    if ($this->capabilities()->nativeIdempotency && $idempotencyKey !== '') {
      $previous = $this->state->rememberedKey($idempotencyKey);
      if ($previous !== NULL) {
        return $previous;
      }
    }

    $this->gate('upsert');

    $rejectField = (string) $this->state->fault('reject_field', '');
    if ($rejectField !== '' && $record->has($rejectField)) {
      $rejectValue = (string) $this->state->fault('reject_value', '');
      if ((string) $record->get($rejectField) === $rejectValue) {
        throw new ConnectorException(
          sprintf('Property "%s" failed a validation rule configured in the CRM account.', $rejectField),
          FailureKind::Permanent,
        );
      }
    }

    $written = $this->state->write($object, $record->remoteId, $record->fields);
    $result = new WriteResult(
      $written['id'],
      $written['created'],
      $written['updated'],
      $this->capabilities()->etags ? (string) $written['version'] : '',
    );

    if ($this->capabilities()->nativeIdempotency && $idempotencyKey !== '') {
      $this->state->rememberKey($idempotencyKey, $result);
    }
    $this->state->queueDelivery(new WebhookEvent(
      $object,
      $written['id'],
      FALSE,
      $this->state->record($object, $written['id']),
    ));

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function delete(string $object, string $remoteId, string $idempotencyKey): void {
    $this->gate('delete');

    // Deleting a record that is already gone succeeds. A retry after a delete
    // that landed is the normal case, and failing it makes every delete a
    // coin flip.
    if ($this->state->remove($object, $remoteId)) {
      $this->state->queueDelivery(new WebhookEvent($object, $remoteId, TRUE));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function verifyWebhook(Request $request): WebhookVerification {
    if (!$this->capabilities()->webhooks) {
      throw new ConnectorException(
        'This connector does not accept webhooks.',
        FailureKind::Permanent,
      );
    }

    // Authenticate, check freshness, then parse. Reading the body first would
    // mean acting on attacker-controlled JSON before knowing the request came
    // from the peer at all.
    $rawTimestamp = (string) $request->headers->get(self::HEADER_TIMESTAMP, '');
    if ($rawTimestamp === '' || !ctype_digit($rawTimestamp)) {
      throw new ConnectorException('Missing or malformed timestamp.', FailureKind::Auth);
    }
    $timestamp = (int) $rawTimestamp;

    $body = (string) $request->getContent();
    $expected = self::sign($this->secret(), $timestamp, $body);
    $given = (string) $request->headers->get(self::HEADER_SIGNATURE, '');
    // hash_equals, never ==: a byte-by-byte comparison leaks through its
    // timing how much of the signature was right.
    if (!hash_equals($expected, $given)) {
      throw new ConnectorException('Bad signature.', FailureKind::Auth);
    }

    $age = abs($this->state->now() - $timestamp);
    if ($age > self::SIGNATURE_WINDOW) {
      throw new ConnectorException(
        sprintf('Delivery is %d seconds old; the window is %d.', $age, self::SIGNATURE_WINDOW),
        FailureKind::Auth,
      );
    }

    $decoded = json_decode($body, TRUE);
    if (!is_array($decoded) || !isset($decoded['events']) || !is_array($decoded['events'])) {
      // Authentic but unparseable is the peer's bug, not an attack. Reporting
      // it as an auth failure would page the wrong person.
      throw new ConnectorException('Malformed delivery body.', FailureKind::Permanent);
    }

    $events = [];
    foreach ($decoded['events'] as $raw) {
      if (!is_array($raw)) {
        continue;
      }
      $fields = isset($raw['fields']) && is_array($raw['fields']) ? $raw['fields'] : NULL;
      $events[] = new WebhookEvent(
        (string) ($raw['object'] ?? ''),
        (string) ($raw['remote_id'] ?? ''),
        (bool) ($raw['deleted'] ?? FALSE),
        $fields === NULL ? NULL : new RemoteRecord(
          (string) ($raw['remote_id'] ?? ''),
          $fields,
          (int) ($raw['updated_at'] ?? 0),
        ),
      );
    }

    return new WebhookVerification(
      (string) $request->headers->get(self::HEADER_DELIVERY, ''),
      $events,
    );
  }

  /**
   * The webhook signing secret.
   *
   * @return string
   *   The secret.
   */
  private function secret(): string {
    $secret = $this->credentials->value($this->getPluginId(), self::SECRET_NAME);
    return $secret !== '' ? $secret : 'fake-secret';
  }

  /**
   * Signs a delivery the way this peer does.
   *
   * The timestamp is inside the signed material on purpose. Signing the body
   * alone yields a signature that stays valid forever, so one captured
   * delivery becomes replayable indefinitely.
   *
   * @param string $secret
   *   The signing secret.
   * @param int $timestamp
   *   The delivery timestamp.
   * @param string $body
   *   The delivery body.
   *
   * @return string
   *   The hex signature.
   */
  public static function sign(string $secret, int $timestamp, string $body): string {
    return hash_hmac('sha256', $timestamp . '.' . $body, $secret);
  }

}
