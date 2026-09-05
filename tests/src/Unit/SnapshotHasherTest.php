<?php

declare(strict_types=1);

namespace Drupal\Tests\crm_bridge\Unit;

use Drupal\crm_bridge\Service\SnapshotHasher;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the digest that conflict detection and echo suppression share.
 *
 * @coversDefaultClass \Drupal\crm_bridge\Service\SnapshotHasher
 *
 * @group crm_bridge
 */
class SnapshotHasherTest extends UnitTestCase {

  /**
   * The hasher under test.
   */
  protected SnapshotHasher $hasher;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->hasher = new SnapshotHasher();
  }

  /**
   * The digest must not depend on the order the fields arrive in.
   *
   * @covers ::hash
   */
  public function testDigestIsIndependentOfKeyOrder(): void {
    $names = ['mail', 'first_name', 'last_name'];
    $a = ['mail' => 'a@b.c', 'first_name' => 'Ann', 'last_name' => 'Lee'];
    $b = ['last_name' => 'Lee', 'mail' => 'a@b.c', 'first_name' => 'Ann'];

    $this->assertSame(
      $this->hasher->hash($a, $names),
      $this->hasher->hash($b, $names)
    );
  }

  /**
   * A field the mapping does not own must not be able to cause a write.
   *
   * @covers ::hash
   */
  public function testUnmappedFieldsAreIgnored(): void {
    $names = ['mail'];

    $this->assertSame(
      $this->hasher->hash(['mail' => 'a@b.c'], $names),
      $this->hasher->hash(
        ['mail' => 'a@b.c', 'hs_last_page_seen' => '/pricing'],
        $names
      )
    );
  }

  /**
   * A JSON round-trip turns 1 into 1.0; that is not a change.
   *
   * @covers ::hash
   */
  public function testWholeFloatsMatchTheirIntegers(): void {
    $names = ['score'];

    $this->assertSame(
      $this->hasher->hash(['score' => 42], $names),
      $this->hasher->hash(['score' => 42.0], $names)
    );
  }

  /**
   * The three empty-ish states mean different things and must stay distinct.
   *
   * @covers ::hash
   */
  public function testAbsentEmptyAndNullAreDistinct(): void {
    $names = ['phone'];

    $digests = [
      $this->hasher->hash([], $names),
      $this->hasher->hash(['phone' => ''], $names),
      $this->hasher->hash(['phone' => NULL], $names),
    ];

    $this->assertCount(3, array_unique($digests));
  }

  /**
   * Values of different types must not collapse onto one digest.
   *
   * @covers ::hash
   */
  public function testTypesAreDistinguished(): void {
    $names = ['v'];

    $digests = [
      $this->hasher->hash(['v' => '1'], $names),
      $this->hasher->hash(['v' => 1], $names),
      $this->hasher->hash(['v' => TRUE], $names),
    ];

    $this->assertCount(3, array_unique($digests));
  }

  /**
   * Length prefixing stops adjacent fields from being repacked.
   *
   * @covers ::hash
   */
  public function testFieldBoundariesCannotCollide(): void {
    $names = ['a', 'b'];

    $this->assertNotSame(
      $this->hasher->hash(['a' => 'ab', 'b' => 'c'], $names),
      $this->hasher->hash(['a' => 'a', 'b' => 'bc'], $names)
    );
  }

  /**
   * The same instant in two time zones is the same value.
   *
   * @covers ::hash
   */
  public function testTimeZonesAreNormalised(): void {
    $names = ['created'];
    $utc = new \DateTimeImmutable('2026-09-05T12:00:00', new \DateTimeZone('UTC'));
    $kyiv = $utc->setTimezone(new \DateTimeZone('Europe/Kyiv'));

    $this->assertSame(
      $this->hasher->hash(['created' => $utc], $names),
      $this->hasher->hash(['created' => $kyiv], $names)
    );
  }

  /**
   * A CRM may return object keys in any order, so they are sorted.
   *
   * @covers ::hash
   */
  public function testAssociativeArrayKeyOrderIsIgnored(): void {
    $names = ['address'];

    $this->assertSame(
      $this->hasher->hash(['address' => ['city' => 'Kyiv', 'zip' => '01001']], $names),
      $this->hasher->hash(['address' => ['zip' => '01001', 'city' => 'Kyiv']], $names)
    );
  }

  /**
   * The order of a multi-value field is meaningful, so lists keep it.
   *
   * @covers ::hash
   */
  public function testListOrderIsSignificant(): void {
    $names = ['tags'];

    $this->assertNotSame(
      $this->hasher->hash(['tags' => ['a', 'b']], $names),
      $this->hasher->hash(['tags' => ['b', 'a']], $names)
    );
  }

  /**
   * A change to a mapped value must change the digest.
   *
   * @covers ::hash
   */
  public function testMappedValueChangeChangesTheDigest(): void {
    $names = ['mail'];

    $this->assertNotSame(
      $this->hasher->hash(['mail' => 'a@b.c'], $names),
      $this->hasher->hash(['mail' => 'd@e.f'], $names)
    );
  }

  /**
   * A repeated field name must not be hashed twice.
   *
   * @covers ::hash
   */
  public function testDuplicateNamesAreCollapsed(): void {
    $values = ['mail' => 'a@b.c'];

    $this->assertSame(
      $this->hasher->hash($values, ['mail']),
      $this->hasher->hash($values, ['mail', 'mail'])
    );
  }

}
