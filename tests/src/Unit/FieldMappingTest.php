<?php

declare(strict_types=1);

namespace Drupal\Tests\crm_bridge\Unit;

use Drupal\crm_bridge\Mapping\Direction;
use Drupal\crm_bridge\Mapping\FieldMapping;
use Drupal\crm_bridge\Mapping\Transform;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the field mapping value object.
 *
 * @coversDefaultClass \Drupal\crm_bridge\Mapping\FieldMapping
 *
 * @group crm_bridge
 */
class FieldMappingTest extends UnitTestCase {

  /**
   * @covers ::fromArray
   * @covers ::toArray
   */
  public function testArrayRoundTrip(): void {
    $stored = [
      'drupal' => 'mail',
      'remote' => 'email',
      'transform' => Transform::EMAIL_NORMALIZE,
      'direction' => Direction::PUSH,
    ];

    $this->assertSame($stored, FieldMapping::fromArray($stored)->toArray());
  }

  /**
   * A partial stored row must not produce nulls the rest of the code trips on.
   *
   * @covers ::fromArray
   */
  public function testFromArrayToleratesMissingKeys(): void {
    $field = FieldMapping::fromArray(['drupal' => 'mail', 'remote' => 'email']);

    $this->assertSame('', $field->transform);
    $this->assertSame('', $field->direction);
  }

  /**
   * @covers ::validate
   */
  public function testValidMappingHasNoProblems(): void {
    $field = new FieldMapping('mail', 'email', Transform::EMAIL_NORMALIZE);

    $this->assertSame([], $field->validate(Direction::BIDIRECTIONAL));
  }

  /**
   * @covers ::validate
   */
  public function testEmptySidesAreReported(): void {
    $problems = (new FieldMapping('', ''))->validate(Direction::BIDIRECTIONAL);

    $this->assertCount(2, $problems);
    $this->assertStringContainsString('Drupal field', $problems[0]);
    $this->assertStringContainsString('remote field', $problems[1]);
  }

  /**
   * @covers ::validate
   */
  public function testUnknownTransformIsReported(): void {
    $problems = (new FieldMapping('mail', 'email', 'shout'))->validate(Direction::BIDIRECTIONAL);

    $this->assertCount(1, $problems);
    $this->assertStringContainsString('shout', $problems[0]);
  }

  /**
   * A field that could never sync is a misconfiguration, not a no-op.
   *
   * Ignoring it quietly is how somebody spends an afternoon wondering why one
   * field never reaches the CRM.
   *
   * @covers ::validate
   * @covers ::effectiveDirection
   */
  public function testContradictoryDirectionIsReported(): void {
    $field = new FieldMapping('mail', 'email', '', Direction::PULL);

    $this->assertNull($field->effectiveDirection(Direction::PUSH));

    $problems = $field->validate(Direction::PUSH);
    $this->assertCount(1, $problems);
    $this->assertStringContainsString('never sync', $problems[0]);
  }

  /**
   * @covers ::validate
   */
  public function testUnknownDirectionIsReportedOnce(): void {
    $problems = (new FieldMapping('mail', 'email', '', 'sideways'))
      ->validate(Direction::BIDIRECTIONAL);

    // One problem, not two: an unknown direction is not also a contradiction,
    // and reporting both would send the reader looking for a second bug.
    $this->assertCount(1, $problems);
    $this->assertStringContainsString('Unknown direction', $problems[0]);
  }

  /**
   * @covers ::effectiveDirection
   */
  public function testEffectiveDirectionNarrows(): void {
    $field = new FieldMapping('mail', 'email', '', Direction::PUSH);

    $this->assertSame(Direction::PUSH, $field->effectiveDirection(Direction::BIDIRECTIONAL));
    $this->assertSame(Direction::PUSH, $field->effectiveDirection(Direction::PUSH));
  }

}
