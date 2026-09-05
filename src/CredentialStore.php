<?php

declare(strict_types=1);

namespace Drupal\crm_bridge;

use Drupal\Core\State\StateInterface;

/**
 * Holds connector credentials outside configuration.
 *
 * This is a small class with one job and one reason to exist: a token stored
 * in a configuration entity ends up in config/sync, and config/sync ends up in
 * git. The State API keeps credentials out of the export, and the mapping
 * entity's tests assert that nothing resembling a token appears there.
 *
 * Sites that have the key module should point these values at a key instead;
 * that integration is deliberately not a hard dependency, because requiring it
 * would keep the module off sites that manage secrets some other way.
 */
class CredentialStore {

  /**
   * The State key prefix.
   */
  private const PREFIX = 'crm_bridge.credentials.';

  /**
   * Constructs the store.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   */
  public function __construct(
    protected readonly StateInterface $state,
  ) {}

  /**
   * Reads a connector's credentials.
   *
   * @param string $connector
   *   The connector plugin ID.
   *
   * @return array<string, string>
   *   The credentials, empty when none are set.
   */
  public function get(string $connector): array {
    $values = $this->state->get(self::PREFIX . $connector, []);
    if (!is_array($values)) {
      return [];
    }
    $out = [];
    foreach ($values as $name => $value) {
      $out[(string) $name] = (string) $value;
    }
    return $out;
  }

  /**
   * Reads one credential.
   *
   * @param string $connector
   *   The connector plugin ID.
   * @param string $name
   *   The credential name.
   *
   * @return string
   *   The value, empty when unset.
   */
  public function value(string $connector, string $name): string {
    return $this->get($connector)[$name] ?? '';
  }

  /**
   * Replaces a connector's credentials.
   *
   * Values that arrive empty are dropped rather than stored, so that a form
   * submitted with a blank field does not overwrite a live token with an
   * empty string.
   *
   * @param string $connector
   *   The connector plugin ID.
   * @param array<string, string> $values
   *   The credentials.
   */
  public function set(string $connector, array $values): void {
    $existing = $this->get($connector);
    foreach ($values as $name => $value) {
      $value = trim($value);
      if ($value === '') {
        continue;
      }
      $existing[(string) $name] = $value;
    }
    $this->state->set(self::PREFIX . $connector, $existing);
  }

  /**
   * Removes one credential.
   *
   * @param string $connector
   *   The connector plugin ID.
   * @param string $name
   *   The credential name.
   */
  public function clear(string $connector, string $name): void {
    $values = $this->get($connector);
    unset($values[$name]);
    $this->state->set(self::PREFIX . $connector, $values);
  }

  /**
   * Removes every credential for a connector.
   *
   * @param string $connector
   *   The connector plugin ID.
   */
  public function clearAll(string $connector): void {
    $this->state->delete(self::PREFIX . $connector);
  }

  /**
   * Which credentials are set, without revealing their values.
   *
   * The status report and doctor need to say "the token is missing" without
   * printing the token, so this returns names only.
   *
   * @param string $connector
   *   The connector plugin ID.
   *
   * @return list<string>
   *   The credential names that hold a value.
   */
  public function names(string $connector): array {
    return array_keys($this->get($connector));
  }

}
