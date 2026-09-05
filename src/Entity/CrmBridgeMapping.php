<?php

declare(strict_types=1);

namespace Drupal\crm_bridge\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\crm_bridge\Mapping\ConflictPolicy;
use Drupal\crm_bridge\Mapping\Direction;
use Drupal\crm_bridge\Mapping\FieldMapping;

/**
 * Defines the CRM mapping configuration entity.
 *
 * The mapping is the module's entire public surface, and it is configuration
 * rather than code on purpose: it is exportable, reviewable in a diff, and
 * deployable through config/sync. A binding that decides what gets written
 * into a customer's CRM belongs in a pull request, not in a database row
 * somebody edited on production.
 *
 * The entity type is declared with an annotation rather than a PHP attribute
 * because this module supports Drupal 10.3, where the ConfigEntityType
 * attribute does not exist yet. It can move to an attribute when the 10.3
 * requirement is dropped.
 *
 * @ConfigEntityType(
 *   id = "crm_bridge_mapping",
 *   label = @Translation("CRM mapping"),
 *   label_collection = @Translation("CRM mappings"),
 *   label_singular = @Translation("CRM mapping"),
 *   label_plural = @Translation("CRM mappings"),
 *   label_count = @PluralTranslation(
 *     singular = "@count CRM mapping",
 *     plural = "@count CRM mappings"
 *   ),
 *   handlers = {
 *     "list_builder" = "Drupal\crm_bridge\MappingListBuilder",
 *     "form" = {
 *       "add" = "Drupal\crm_bridge\Form\MappingForm",
 *       "edit" = "Drupal\crm_bridge\Form\MappingForm",
 *       "delete" = "Drupal\crm_bridge\Form\MappingDeleteForm"
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider"
 *     }
 *   },
 *   config_prefix = "mapping",
 *   admin_permission = "administer crm bridge",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid",
 *     "status" = "status"
 *   },
 *   links = {
 *     "collection" = "/admin/config/services/crm-bridge/mappings",
 *     "add-form" = "/admin/config/services/crm-bridge/mappings/add",
 *     "edit-form" = "/admin/config/services/crm-bridge/mappings/{crm_bridge_mapping}",
 *     "delete-form" = "/admin/config/services/crm-bridge/mappings/{crm_bridge_mapping}/delete"
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "entity_type",
 *     "bundle",
 *     "connector",
 *     "remote_object",
 *     "direction",
 *     "identity",
 *     "conflict",
 *     "fields"
 *   }
 * )
 */
class CrmBridgeMapping extends ConfigEntityBase implements CrmBridgeMappingInterface {

  /**
   * The machine name.
   */
  protected string $id = '';

  /**
   * The human-readable label.
   */
  protected string $label = '';

  /**
   * The Drupal entity type ID.
   */
  protected string $entity_type = '';

  /**
   * The bundle.
   */
  protected string $bundle = '';

  /**
   * The connector plugin ID.
   */
  protected string $connector = '';

  /**
   * The remote object name.
   */
  protected string $remote_object = '';

  /**
   * The sync direction.
   */
  protected string $direction = Direction::BIDIRECTIONAL;

  /**
   * Identity resolution settings.
   *
   * @var array{strategy?: string, keys?: list<string>, on_ambiguous?: string}
   */
  protected array $identity = [];

  /**
   * Conflict resolution settings.
   *
   * @var array{default?: string, per_field?: array<string, string>}
   */
  protected array $conflict = [];

  /**
   * The field mappings in stored array form.
   *
   * @var list<array<string, string>>
   */
  protected array $fields = [];

  /**
   * {@inheritdoc}
   */
  public function getMappedEntityTypeId(): string {
    return $this->entity_type;
  }

  /**
   * {@inheritdoc}
   */
  public function getBundle(): string {
    // A config entity type with no bundles maps the entity type onto itself,
    // which is how user.user works. Defaulting keeps callers from having to
    // special-case it.
    return $this->bundle !== '' ? $this->bundle : $this->entity_type;
  }

  /**
   * {@inheritdoc}
   */
  public function getConnectorId(): string {
    return $this->connector;
  }

  /**
   * {@inheritdoc}
   */
  public function getRemoteObject(): string {
    return $this->remote_object;
  }

  /**
   * {@inheritdoc}
   */
  public function getDirection(): string {
    return $this->direction;
  }

  /**
   * {@inheritdoc}
   */
  public function getFieldMappings(): array {
    return array_values(array_map(
      static fn (array $row): FieldMapping => FieldMapping::fromArray($row),
      $this->fields,
    ));
  }

  /**
   * {@inheritdoc}
   */
  public function getPushFields(): array {
    return $this->fieldsMatching(static fn (string $d): bool => Direction::pushes($d));
  }

  /**
   * {@inheritdoc}
   */
  public function getPullFields(): array {
    return $this->fieldsMatching(static fn (string $d): bool => Direction::pulls($d));
  }

  /**
   * Filters fields by their effective direction.
   *
   * @param callable(string): bool $predicate
   *   Receives the effective direction.
   *
   * @return list<\Drupal\crm_bridge\Mapping\FieldMapping>
   *   The matching field mappings.
   */
  private function fieldsMatching(callable $predicate): array {
    $out = [];
    foreach ($this->getFieldMappings() as $field) {
      $effective = $field->effectiveDirection($this->direction);
      // A contradictory override is a validation error, surfaced by
      // validateStructure(). Here it simply syncs in no direction, so that a
      // mapping saved before the check existed cannot write the wrong way.
      if ($effective !== NULL && $predicate($effective)) {
        $out[] = $field;
      }
    }
    return $out;
  }

  /**
   * {@inheritdoc}
   */
  public function getHashedFieldNames(): array {
    $names = [];
    foreach ($this->getFieldMappings() as $field) {
      if ($field->drupalField !== '') {
        $names[] = $field->drupalField;
      }
    }
    return array_values(array_unique($names));
  }

  /**
   * {@inheritdoc}
   */
  public function getIdentityKeys(): array {
    return array_values(array_filter($this->identity['keys'] ?? []));
  }

  /**
   * {@inheritdoc}
   */
  public function getOnAmbiguous(): string {
    return $this->identity['on_ambiguous'] ?? 'review';
  }

  /**
   * {@inheritdoc}
   */
  public function getConflictPolicy(string $drupalField = ''): string {
    if ($drupalField !== '' && isset($this->conflict['per_field'][$drupalField])) {
      return $this->conflict['per_field'][$drupalField];
    }
    // Defaulting to review rather than to a winner is deliberate: a mapping
    // that was never told what to do must not decide on its own.
    return $this->conflict['default'] ?? ConflictPolicy::REVIEW;
  }

  /**
   * {@inheritdoc}
   */
  public function validateStructure(): array {
    $problems = [];

    foreach (
      [
        'entity_type' => $this->entity_type,
        'connector' => $this->connector,
        'remote_object' => $this->remote_object,
      ] as $name => $value
    ) {
      if ($value === '') {
        $problems[] = sprintf('The "%s" setting is required.', $name);
      }
    }

    if (!Direction::isValid($this->direction)) {
      $problems[] = sprintf('Unknown direction "%s".', $this->direction);
    }

    $onAmbiguous = $this->getOnAmbiguous();
    if (!in_array($onAmbiguous, ['review', 'create', 'skip'], TRUE)) {
      $problems[] = sprintf('Unknown ambiguity policy "%s".', $onAmbiguous);
    }

    $default = $this->conflict['default'] ?? ConflictPolicy::REVIEW;
    if (!ConflictPolicy::isValid($default)) {
      $problems[] = sprintf('Unknown conflict policy "%s".', $default);
    }

    if ($this->fields === []) {
      $problems[] = 'The mapping has no fields, so it would sync nothing.';
    }

    $seen = [];
    foreach ($this->getFieldMappings() as $index => $field) {
      foreach ($field->validate($this->direction) as $problem) {
        $problems[] = sprintf('Field %d: %s', $index + 1, $problem);
      }
      if ($field->drupalField !== '') {
        if (isset($seen[$field->drupalField])) {
          $problems[] = sprintf(
            'Field %d: "%s" is mapped twice, so one of the two would silently win.',
            $index + 1,
            $field->drupalField,
          );
        }
        $seen[$field->drupalField] = TRUE;
      }
    }

    // A per-field policy naming a field this mapping does not carry is dead
    // configuration, and dead configuration is nearly always a rename that was
    // only half applied. Falling back to the default would hide it.
    foreach ($this->conflict['per_field'] ?? [] as $name => $policy) {
      if (!isset($seen[$name])) {
        $problems[] = sprintf('Conflict policy is set for "%s", which is not a mapped field.', $name);
      }
      if (!ConflictPolicy::isValid((string) $policy)) {
        $problems[] = sprintf('Unknown conflict policy "%s" for field "%s".', $policy, $name);
      }
    }

    foreach ($this->getIdentityKeys() as $key) {
      if (!isset($seen[$key])) {
        $problems[] = sprintf(
          'Identity key "%s" is not a mapped field, so it can never be compared.',
          $key,
        );
      }
    }

    return $problems;
  }

}
