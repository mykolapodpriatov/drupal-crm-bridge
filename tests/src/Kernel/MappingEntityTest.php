<?php

declare(strict_types=1);

namespace Drupal\Tests\crm_bridge\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\crm_bridge\Entity\CrmBridgeMapping;
use Drupal\crm_bridge\Entity\CrmBridgeMappingInterface;
use Drupal\crm_bridge\Mapping\ConflictPolicy;
use Drupal\crm_bridge\Mapping\Direction;
use Drupal\crm_bridge\Mapping\FieldMapping;
use Drupal\crm_bridge\Mapping\Transform;

/**
 * Tests the CRM mapping configuration entity.
 *
 * KernelTestBase validates every saved configuration object against the
 * module's schema, so every save in this file is also an assertion that
 * crm_bridge.schema.yml describes what the entity actually stores. A schema
 * that drifts from the entity fails here rather than on somebody's site
 * during a config import.
 *
 * @group crm_bridge
 */
class MappingEntityTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'crm_bridge'];

  /**
   * Builds a mapping with sensible defaults, overridable per test.
   *
   * @param array<string, mixed> $overrides
   *   Values to override.
   *
   * @return \Drupal\crm_bridge\Entity\CrmBridgeMappingInterface
   *   The unsaved mapping.
   */
  private function mapping(array $overrides = []): CrmBridgeMappingInterface {
    $values = [
      'id' => 'user_contact',
      'label' => 'Users to contacts',
      'entity_type' => 'user',
      'bundle' => '',
      'connector' => 'hubspot',
      'remote_object' => 'contacts',
      'direction' => Direction::BIDIRECTIONAL,
      'identity' => [
        'strategy' => 'deterministic',
        'keys' => ['mail'],
        'on_ambiguous' => 'review',
      ],
      'conflict' => [
        'default' => ConflictPolicy::NEWEST_WINS,
        'per_field' => ['mail' => ConflictPolicy::DRUPAL_WINS],
      ],
      'fields' => [
        [
          'drupal' => 'mail',
          'remote' => 'email',
          'transform' => Transform::EMAIL_NORMALIZE,
          'direction' => '',
        ],
        [
          'drupal' => 'created',
          'remote' => 'createdate',
          'transform' => '',
          'direction' => Direction::PUSH,
        ],
      ],
    ];

    foreach ($overrides as $key => $value) {
      $values[$key] = $value;
    }

    return CrmBridgeMapping::create($values);
  }

  /**
   * A mapping survives a save and reload with every value intact.
   */
  public function testSaveAndReload(): void {
    $this->mapping()->save();

    $loaded = $this->container->get('entity_type.manager')
      ->getStorage('crm_bridge_mapping')
      ->load('user_contact');

    $this->assertInstanceOf(CrmBridgeMappingInterface::class, $loaded);
    $this->assertSame('user', $loaded->getMappedEntityTypeId());
    $this->assertSame('hubspot', $loaded->getConnectorId());
    $this->assertSame('contacts', $loaded->getRemoteObject());
    $this->assertSame(Direction::BIDIRECTIONAL, $loaded->getDirection());
    $this->assertSame(['mail'], $loaded->getIdentityKeys());
    $this->assertSame('review', $loaded->getOnAmbiguous());
    $this->assertCount(2, $loaded->getFieldMappings());
  }

  /**
   * An entity type without bundles maps onto itself.
   */
  public function testBundleDefaultsToTheEntityType(): void {
    $this->assertSame('user', $this->mapping(['bundle' => ''])->getBundle());
    $this->assertSame('article', $this->mapping([
      'entity_type' => 'node',
      'bundle' => 'article',
    ])->getBundle());
  }

  /**
   * Per-field direction overrides decide which fields each path carries.
   */
  public function testPushAndPullFieldsRespectOverrides(): void {
    $mapping = $this->mapping();

    $name = static fn (FieldMapping $f): string => $f->drupalField;
    $push = array_map($name, $mapping->getPushFields());
    $pull = array_map($name, $mapping->getPullFields());

    // "created" is push-only, so it appears on the way out and not on the way
    // back. A read-only remote field that came back would overwrite the local
    // creation date with whatever the CRM believes it is.
    $this->assertSame(['mail', 'created'], $push);
    $this->assertSame(['mail'], $pull);
  }

  /**
   * A push-only mapping never yields pull fields, whatever the overrides say.
   */
  public function testPushOnlyMappingYieldsNoPullFields(): void {
    $mapping = $this->mapping([
      'direction' => Direction::PUSH,
      'fields' => [
        ['drupal' => 'mail', 'remote' => 'email', 'transform' => '', 'direction' => ''],
        // Contradictory, and reported by validation. It must still never
        // produce a pull, so that a mapping saved before the check existed
        // cannot write the wrong way.
        ['drupal' => 'name', 'remote' => 'firstname', 'transform' => '', 'direction' => Direction::PULL],
      ],
    ]);

    $this->assertSame([], $mapping->getPullFields());
    $this->assertCount(1, $mapping->getPushFields());
  }

  /**
   * Only mapped Drupal fields take part in the digest.
   */
  public function testHashedFieldNamesAreTheMappedOnes(): void {
    $this->assertSame(['mail', 'created'], $this->mapping()->getHashedFieldNames());
  }

  /**
   * The per-field policy wins over the default, and the default is review.
   */
  public function testConflictPolicyResolution(): void {
    $mapping = $this->mapping();

    $this->assertSame(ConflictPolicy::DRUPAL_WINS, $mapping->getConflictPolicy('mail'));
    $this->assertSame(ConflictPolicy::NEWEST_WINS, $mapping->getConflictPolicy('created'));
    $this->assertSame(ConflictPolicy::NEWEST_WINS, $mapping->getConflictPolicy());

    $bare = $this->mapping(['conflict' => []]);
    $this->assertSame(ConflictPolicy::REVIEW, $bare->getConflictPolicy());
    $this->assertSame(ConflictPolicy::REVIEW, $bare->getConflictPolicy('mail'));
  }

  /**
   * Explicit per-field policies are distinguishable from inherited ones.
   *
   * The form needs this: editing a mapping must not silently pin every field
   * to whatever default happened to be in force at the time.
   */
  public function testPerFieldPoliciesReportOnlyExplicitOnes(): void {
    $this->assertSame(
      ['mail' => ConflictPolicy::DRUPAL_WINS],
      $this->mapping()->getPerFieldPolicies(),
    );
    $this->assertSame([], $this->mapping(['conflict' => []])->getPerFieldPolicies());
  }

  /**
   * A well-formed mapping reports no structural problems.
   */
  public function testValidMappingIsStructurallyValid(): void {
    $this->assertSame([], $this->mapping()->validateStructure());
  }

  /**
   * A mapping with no fields would sync nothing, which is never intended.
   */
  public function testEmptyFieldListIsReported(): void {
    $problems = $this->mapping(['fields' => []])->validateStructure();

    $this->assertNotEmpty($problems);
    $this->assertStringContainsString('no fields', implode("\n", $problems));
  }

  /**
   * The same Drupal field mapped twice means one silently wins.
   */
  public function testDuplicateFieldIsReported(): void {
    $problems = $this->mapping([
      'identity' => ['strategy' => 'deterministic', 'keys' => [], 'on_ambiguous' => 'review'],
      'conflict' => ['default' => ConflictPolicy::REVIEW, 'per_field' => []],
      'fields' => [
        ['drupal' => 'mail', 'remote' => 'email', 'transform' => '', 'direction' => ''],
        ['drupal' => 'mail', 'remote' => 'work_email', 'transform' => '', 'direction' => ''],
      ],
    ])->validateStructure();

    $this->assertStringContainsString('mapped twice', implode("\n", $problems));
  }

  /**
   * A policy for a field this mapping does not carry is a half-applied rename.
   */
  public function testPolicyForUnmappedFieldIsReported(): void {
    $problems = $this->mapping([
      'conflict' => [
        'default' => ConflictPolicy::REVIEW,
        'per_field' => ['lifecyclestage' => ConflictPolicy::CRM_WINS],
      ],
    ])->validateStructure();

    $this->assertStringContainsString('lifecyclestage', implode("\n", $problems));
  }

  /**
   * An identity key that is not mapped can never be compared against anything.
   */
  public function testIdentityKeyMustBeMapped(): void {
    $problems = $this->mapping([
      'identity' => [
        'strategy' => 'deterministic',
        'keys' => ['phone'],
        'on_ambiguous' => 'review',
      ],
    ])->validateStructure();

    $this->assertStringContainsString('phone', implode("\n", $problems));
  }

  /**
   * Unknown enum values are reported rather than silently defaulted.
   */
  public function testUnknownEnumsAreProblems(): void {
    $problems = $this->mapping([
      'direction' => 'sideways',
      'identity' => ['strategy' => 'deterministic', 'keys' => [], 'on_ambiguous' => 'merge'],
      'conflict' => ['default' => 'coin_flip', 'per_field' => []],
    ])->validateStructure();

    $joined = implode("\n", $problems);
    $this->assertStringContainsString('sideways', $joined);
    $this->assertStringContainsString('merge', $joined);
    $this->assertStringContainsString('coin_flip', $joined);
  }

  /**
   * Validation reports everything at once, not just the first problem.
   *
   * One pass through the form, or one run of doctor, should be enough to fix a
   * whole mapping.
   */
  public function testEveryProblemIsReportedTogether(): void {
    $problems = $this->mapping([
      'connector' => '',
      'remote_object' => '',
      'direction' => 'sideways',
      'fields' => [],
    ])->validateStructure();

    $this->assertGreaterThanOrEqual(4, count($problems));
  }

  /**
   * The exported configuration is exactly what the schema describes.
   */
  public function testExportedShapeMatchesTheSchema(): void {
    $this->mapping()->save();

    $exported = $this->config('crm_bridge.mapping.user_contact')->get();

    foreach (
      [
        'id', 'label', 'entity_type', 'bundle', 'connector', 'remote_object',
        'direction', 'identity', 'conflict', 'fields', 'uuid', 'langcode',
        'status', 'dependencies',
      ] as $key
    ) {
      $this->assertArrayHasKey($key, $exported, $key);
    }

    // Credentials must never reach configuration: config/sync ends up in git.
    // This is asserted rather than left to discipline, because the connector
    // settings form lands in the next milestone and this is the boundary it
    // must not cross.
    $serialised = print_r($exported, TRUE);
    $this->assertStringNotContainsStringIgnoringCase('token', $serialised);
    $this->assertStringNotContainsStringIgnoringCase('secret', $serialised);
  }

}
