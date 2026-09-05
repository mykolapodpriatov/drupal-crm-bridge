<?php

declare(strict_types=1);

namespace Drupal\Tests\crm_bridge\Unit;

use Drupal\crm_bridge\Mapping\ConflictPolicy;
use Drupal\crm_bridge\Mapping\Transform;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the conflict policy and transform vocabularies.
 *
 * @group crm_bridge
 */
class PolicyAndTransformTest extends UnitTestCase {

  /**
   * @covers \Drupal\crm_bridge\Mapping\ConflictPolicy::isValid
   */
  public function testPolicyValidity(): void {
    foreach (ConflictPolicy::all() as $policy) {
      $this->assertTrue(ConflictPolicy::isValid($policy), $policy);
    }
    $this->assertFalse(ConflictPolicy::isValid('coin_flip'));
    $this->assertFalse(ConflictPolicy::isValid(''));
  }

  /**
   * Review is the one policy that refuses to decide, and everything else does.
   *
   * @covers \Drupal\crm_bridge\Mapping\ConflictPolicy::isAutomatic
   */
  public function testOnlyReviewIsManual(): void {
    $this->assertFalse(ConflictPolicy::isAutomatic(ConflictPolicy::REVIEW));

    foreach (ConflictPolicy::all() as $policy) {
      if ($policy === ConflictPolicy::REVIEW) {
        continue;
      }
      $this->assertTrue(ConflictPolicy::isAutomatic($policy), $policy);
    }
  }

  /**
   * @covers \Drupal\crm_bridge\Mapping\Transform::isKnown
   */
  public function testTransformValidity(): void {
    foreach (Transform::all() as $transform) {
      $this->assertTrue(Transform::isKnown($transform), $transform);
    }
    $this->assertFalse(Transform::isKnown('shout'));
    $this->assertFalse(Transform::isKnown(''));
  }

}
