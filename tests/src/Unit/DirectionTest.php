<?php

declare(strict_types=1);

namespace Drupal\Tests\crm_bridge\Unit;

use Drupal\crm_bridge\Mapping\Direction;
use Drupal\Tests\UnitTestCase;

/**
 * Tests direction narrowing.
 *
 * @coversDefaultClass \Drupal\crm_bridge\Mapping\Direction
 *
 * @group crm_bridge
 */
class DirectionTest extends UnitTestCase {

  /**
   * The narrowing matrix, stated exhaustively.
   *
   * @return array<string, array{string, string, string|null}>
   *   Mapping direction, field override, expected effective direction.
   */
  public static function narrowCases(): array {
    return [
      'no override keeps the mapping direction' => [
        Direction::BIDIRECTIONAL, '', Direction::BIDIRECTIONAL,
      ],
      'no override on a push mapping' => [
        Direction::PUSH, '', Direction::PUSH,
      ],
      'bidirectional narrowed to push' => [
        Direction::BIDIRECTIONAL, Direction::PUSH, Direction::PUSH,
      ],
      'bidirectional narrowed to pull' => [
        Direction::BIDIRECTIONAL, Direction::PULL, Direction::PULL,
      ],
      'push and push agree' => [
        Direction::PUSH, Direction::PUSH, Direction::PUSH,
      ],
      // The rule that earns this class its keep: an override may narrow but
      // never widen. A field set to bidirectional inside a push-only mapping
      // stays a push, it does not acquire a pull.
      'a field cannot widen a push mapping' => [
        Direction::PUSH, Direction::BIDIRECTIONAL, Direction::PUSH,
      ],
      'a field cannot widen a pull mapping' => [
        Direction::PULL, Direction::BIDIRECTIONAL, Direction::PULL,
      ],
      // And it cannot contradict it either. Nothing left to sync is an error
      // to report, not a direction to fall back to.
      'pull inside a push mapping is a contradiction' => [
        Direction::PUSH, Direction::PULL, NULL,
      ],
      'push inside a pull mapping is a contradiction' => [
        Direction::PULL, Direction::PUSH, NULL,
      ],
      'an unknown override is rejected' => [
        Direction::BIDIRECTIONAL, 'sideways', NULL,
      ],
      'an unknown mapping direction is rejected' => [
        'sideways', '', NULL,
      ],
    ];
  }

  /**
   * @covers ::narrow
   *
   * @dataProvider narrowCases
   */
  public function testNarrow(string $mapping, string $field, ?string $expected): void {
    $this->assertSame($expected, Direction::narrow($mapping, $field));
  }

  /**
   * @covers ::pushes
   * @covers ::pulls
   */
  public function testPushesAndPulls(): void {
    $this->assertTrue(Direction::pushes(Direction::PUSH));
    $this->assertFalse(Direction::pulls(Direction::PUSH));

    $this->assertFalse(Direction::pushes(Direction::PULL));
    $this->assertTrue(Direction::pulls(Direction::PULL));

    $this->assertTrue(Direction::pushes(Direction::BIDIRECTIONAL));
    $this->assertTrue(Direction::pulls(Direction::BIDIRECTIONAL));

    // An unknown direction must not be treated as permission to do anything.
    $this->assertFalse(Direction::pushes('sideways'));
    $this->assertFalse(Direction::pulls('sideways'));
  }

  /**
   * @covers ::isValid
   * @covers ::all
   */
  public function testValidity(): void {
    foreach (Direction::all() as $direction) {
      $this->assertTrue(Direction::isValid($direction), $direction);
    }
    $this->assertFalse(Direction::isValid(''));
    $this->assertFalse(Direction::isValid('PUSH'));
  }

}
