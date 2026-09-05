<?php

declare(strict_types=1);

namespace Drupal\crm_bridge\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Defines a binding between a Drupal bundle and a remote CRM object.
 */
interface CrmBridgeMappingInterface extends ConfigEntityInterface {

  /**
   * The Drupal entity type this mapping applies to.
   *
   * @return string
   *   The entity type ID.
   */
  public function getMappedEntityTypeId(): string;

  /**
   * The bundle this mapping applies to.
   *
   * @return string
   *   The bundle name.
   */
  public function getBundle(): string;

  /**
   * The connector plugin this mapping syncs through.
   *
   * @return string
   *   The plugin ID.
   */
  public function getConnectorId(): string;

  /**
   * The remote object name, such as "contacts".
   *
   * @return string
   *   The remote object.
   */
  public function getRemoteObject(): string;

  /**
   * The mapping's sync direction.
   *
   * @return string
   *   One of the Direction constants.
   */
  public function getDirection(): string;

  /**
   * The field mappings.
   *
   * @return list<\Drupal\crm_bridge\Mapping\FieldMapping>
   *   The field mappings, in configured order.
   */
  public function getFieldMappings(): array;

  /**
   * The field mappings that carry Drupal changes to the CRM.
   *
   * @return list<\Drupal\crm_bridge\Mapping\FieldMapping>
   *   The pushable field mappings.
   */
  public function getPushFields(): array;

  /**
   * The field mappings that carry CRM changes to Drupal.
   *
   * @return list<\Drupal\crm_bridge\Mapping\FieldMapping>
   *   The pullable field mappings.
   */
  public function getPullFields(): array;

  /**
   * The Drupal field names taking part in the snapshot digest.
   *
   * These, and only these, are hashed. A field the mapping does not own must
   * not be able to make the module think a record changed.
   *
   * @return list<string>
   *   The Drupal field names.
   */
  public function getHashedFieldNames(): array;

  /**
   * The deterministic keys used to match records when no link row exists.
   *
   * @return list<string>
   *   Drupal field names.
   */
  public function getIdentityKeys(): array;

  /**
   * What to do when a deterministic match is ambiguous.
   *
   * @return string
   *   One of "review", "create" or "skip".
   */
  public function getOnAmbiguous(): string;

  /**
   * The conflict policy for a field.
   *
   * @param string $drupalField
   *   The Drupal field name, or an empty string for the mapping default.
   *
   * @return string
   *   One of the ConflictPolicy constants.
   */
  public function getConflictPolicy(string $drupalField = ''): string;

  /**
   * The conflict policies that were explicitly configured per field.
   *
   * Distinct from getConflictPolicy(), which resolves to the mapping default.
   * The form needs to know whether a field has its own policy or is simply
   * inheriting, so that editing a mapping does not silently pin every field
   * to the default that happened to be in force.
   *
   * @return array<string, string>
   *   Policies keyed by Drupal field name.
   */
  public function getPerFieldPolicies(): array;

  /**
   * Lists everything wrong with this mapping.
   *
   * This is structural validation only: it needs no network access and no
   * remote schema, so it can run in form validation, in an update hook and in
   * `drush crm-bridge:doctor` alike. Checking mapped fields against the live
   * remote schema is a separate step that does need the connector.
   *
   * @return list<string>
   *   Human-readable problems, empty when the mapping is structurally valid.
   */
  public function validateStructure(): array;

}
