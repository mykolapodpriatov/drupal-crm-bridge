<?php

declare(strict_types=1);

namespace Drupal\Tests\crm_bridge\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\crm_bridge\CredentialStore;

/**
 * Tests that credentials stay out of configuration.
 *
 * @group crm_bridge
 */
class CredentialStoreTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'crm_bridge'];

  /**
   * The store under test.
   */
  protected CredentialStore $store;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->store = $this->container->get('crm_bridge.credentials');
  }

  /**
   * Values round-trip and are scoped per connector.
   */
  public function testValuesRoundTripPerConnector(): void {
    $this->store->set('hubspot', ['token' => 'hs-token']);
    $this->store->set('twenty', ['token' => 'tw-token']);

    $this->assertSame('hs-token', $this->store->value('hubspot', 'token'));
    $this->assertSame('tw-token', $this->store->value('twenty', 'token'));
    $this->assertSame('', $this->store->value('hubspot', 'absent'));
    $this->assertSame('', $this->store->value('pipedrive', 'token'));
  }

  /**
   * Setting merges rather than replacing.
   */
  public function testSetMergesWithWhatIsAlreadyThere(): void {
    $this->store->set('hubspot', ['token' => 'hs-token']);
    $this->store->set('hubspot', ['webhook_secret' => 'hs-secret']);

    $this->assertSame('hs-token', $this->store->value('hubspot', 'token'));
    $this->assertSame('hs-secret', $this->store->value('hubspot', 'webhook_secret'));
  }

  /**
   * A blank submission does not wipe a live token.
   *
   * The settings form renders credential fields empty, because it must not
   * print secrets back. Saving that form must therefore leave untouched fields
   * alone rather than clearing them.
   */
  public function testBlankValuesDoNotOverwrite(): void {
    $this->store->set('hubspot', ['token' => 'hs-token']);
    $this->store->set('hubspot', ['token' => '', 'webhook_secret' => '  ']);

    $this->assertSame('hs-token', $this->store->value('hubspot', 'token'));
    $this->assertSame('', $this->store->value('hubspot', 'webhook_secret'));
  }

  /**
   * Values are trimmed, because a pasted token usually carries a newline.
   */
  public function testValuesAreTrimmed(): void {
    $this->store->set('hubspot', ['token' => "  hs-token\n"]);
    $this->assertSame('hs-token', $this->store->value('hubspot', 'token'));
  }

  /**
   * Clearing removes one value or all of them.
   */
  public function testClearing(): void {
    $this->store->set('hubspot', ['token' => 'a', 'webhook_secret' => 'b']);

    $this->store->clear('hubspot', 'token');
    $this->assertSame('', $this->store->value('hubspot', 'token'));
    $this->assertSame('b', $this->store->value('hubspot', 'webhook_secret'));

    $this->store->clearAll('hubspot');
    $this->assertSame([], $this->store->get('hubspot'));
  }

  /**
   * Names are reportable without revealing values.
   *
   * The status report has to be able to say "the token is missing" without
   * printing the token.
   */
  public function testNamesRevealNothing(): void {
    $this->store->set('hubspot', ['token' => 'super-secret']);

    $names = $this->store->names('hubspot');
    $this->assertSame(['token'], $names);
    $this->assertStringNotContainsString('super-secret', implode(',', $names));
  }

  /**
   * Nothing the store holds reaches exported configuration.
   *
   * This is the boundary the whole class exists to hold: config/sync ends up
   * in git.
   */
  public function testCredentialsAreNotInConfiguration(): void {
    $this->store->set('hubspot', ['token' => 'super-secret-token']);

    $names = $this->container->get('config.factory')->listAll('crm_bridge');
    foreach ($names as $name) {
      $exported = print_r($this->config($name)->get(), TRUE);
      $this->assertStringNotContainsString('super-secret-token', $exported, $name);
    }
  }

}
