<?php

declare(strict_types=1);

namespace Drupal\crm_bridge\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\crm_bridge\Entity\CrmBridgeMappingInterface;
use Drupal\crm_bridge\Mapping\ConflictPolicy;
use Drupal\crm_bridge\Mapping\Direction;
use Drupal\crm_bridge\Mapping\Transform;

/**
 * Creates and edits CRM mappings.
 *
 * The field map is a plain table with a few spare rows rather than an AJAX
 * "add another" widget. That is a deliberate trade: mappings are configuration
 * and most sites will deploy them through config/sync after editing them once,
 * so the form is for exploring and correcting rather than for bulk authoring,
 * and a table with no JavaScript cannot break in a way that loses somebody's
 * half-entered mapping.
 */
class MappingForm extends EntityForm {

  /**
   * How many blank rows to offer beyond the configured ones.
   */
  private const SPARE_ROWS = 3;

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return array<string, mixed>
   *   The form structure.
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $mapping = $this->entity;
    assert($mapping instanceof CrmBridgeMappingInterface);

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#default_value' => $mapping->label(),
      '#required' => TRUE,
    ];
    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $mapping->id(),
      '#machine_name' => [
        'exists' => [$this, 'mappingExists'],
      ],
      '#disabled' => !$mapping->isNew(),
    ];

    $form['drupal'] = [
      '#type' => 'details',
      '#title' => $this->t('Drupal side'),
      '#open' => TRUE,
    ];
    $form['drupal']['entity_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Entity type'),
      '#options' => $this->contentEntityTypeOptions(),
      '#default_value' => $mapping->getMappedEntityTypeId(),
      '#required' => TRUE,
    ];
    $form['drupal']['bundle'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Bundle'),
      '#default_value' => $mapping->get('bundle'),
      '#description' => $this->t('Leave empty for entity types that have no bundles, such as users.'),
    ];

    $form['remote'] = [
      '#type' => 'details',
      '#title' => $this->t('CRM side'),
      '#open' => TRUE,
    ];
    $form['remote']['connector'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Connector'),
      '#default_value' => $mapping->getConnectorId(),
      '#required' => TRUE,
      '#description' => $this->t('The connector plugin ID, for example "hubspot".'),
    ];
    $form['remote']['remote_object'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Remote object'),
      '#default_value' => $mapping->getRemoteObject(),
      '#required' => TRUE,
      '#description' => $this->t('The object name in the CRM, for example "contacts".'),
    ];

    $form['direction'] = [
      '#type' => 'select',
      '#title' => $this->t('Direction'),
      '#options' => $this->directionOptions(),
      '#default_value' => $mapping->getDirection(),
      '#required' => TRUE,
    ];

    $form['identity'] = [
      '#type' => 'details',
      '#title' => $this->t('Identity'),
      '#open' => TRUE,
      '#description' => $this->t('How a Drupal entity is matched to a CRM record the first time, before a link exists. After that the link is used and these settings are not consulted.'),
    ];
    $form['identity']['identity_keys'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Deterministic keys'),
      '#default_value' => implode(', ', $mapping->getIdentityKeys()),
      '#description' => $this->t('Comma-separated Drupal field names, for example "mail". Each must also be a mapped field.'),
    ];
    $form['identity']['on_ambiguous'] = [
      '#type' => 'select',
      '#title' => $this->t('When more than one record matches'),
      '#options' => [
        'review' => $this->t('Queue it for review (recommended)'),
        'skip' => $this->t('Skip the record'),
        'create' => $this->t('Create a new record'),
      ],
      '#default_value' => $mapping->getOnAmbiguous(),
      '#description' => $this->t('Creating on an ambiguous match is how two real customers become one record. Review is the safe answer.'),
    ];

    $form['conflict_default'] = [
      '#type' => 'select',
      '#title' => $this->t('Default conflict policy'),
      '#options' => $this->policyOptions(),
      '#default_value' => $mapping->getConflictPolicy(),
      '#description' => $this->t('Applied when both sides changed since the last successful sync.'),
    ];

    $form['fields'] = $this->buildFieldTable($mapping);

    return $form;
  }

  /**
   * Builds the field mapping table.
   *
   * @param \Drupal\crm_bridge\Entity\CrmBridgeMappingInterface $mapping
   *   The mapping being edited.
   *
   * @return array<int|string, mixed>
   *   The table render array. Its keys are the render array's own "#" keys
   *   plus one integer key per row, which is why it is not a string-keyed
   *   array like the rest of the form.
   */
  private function buildFieldTable(CrmBridgeMappingInterface $mapping): array {
    $table = [
      '#type' => 'table',
      '#header' => [
        $this->t('Drupal field'),
        $this->t('Remote field'),
        $this->t('Transform'),
        $this->t('Direction'),
        $this->t('Conflict policy'),
      ],
      '#caption' => $this->t('Only the fields listed here are ever read or written. Anything else in the CRM is left alone.'),
      '#empty' => $this->t('No fields yet.'),
    ];

    $rows = $mapping->getFieldMappings();
    $count = count($rows) + self::SPARE_ROWS;

    $policies = $mapping->getPerFieldPolicies();

    // Each row is assigned whole rather than field by field. Writing into
    // $table[$i]['drupal'] would mean reading $table[$i] first, on an array
    // whose inferred shape is the render array's own keys.
    for ($i = 0; $i < $count; $i++) {
      $row = $rows[$i] ?? NULL;
      $drupalField = $row?->drupalField ?? '';

      $table[$i] = [
        'drupal' => [
          '#type' => 'textfield',
          '#title' => $this->t('Drupal field'),
          '#title_display' => 'invisible',
          '#default_value' => $drupalField,
          '#size' => 24,
        ],
        'remote' => [
          '#type' => 'textfield',
          '#title' => $this->t('Remote field'),
          '#title_display' => 'invisible',
          '#default_value' => $row?->remoteField ?? '',
          '#size' => 24,
        ],
        'transform' => [
          '#type' => 'select',
          '#title' => $this->t('Transform'),
          '#title_display' => 'invisible',
          '#options' => ['' => $this->t('None')] + $this->transformOptions(),
          '#default_value' => $row?->transform ?? '',
        ],
        'direction' => [
          '#type' => 'select',
          '#title' => $this->t('Direction'),
          '#title_display' => 'invisible',
          '#options' => ['' => $this->t('Use mapping default')] + $this->directionOptions(),
          '#default_value' => $row?->direction ?? '',
        ],
        'policy' => [
          '#type' => 'select',
          '#title' => $this->t('Conflict policy'),
          '#title_display' => 'invisible',
          '#options' => ['' => $this->t('Use mapping default')] + $this->policyOptions(),
          '#default_value' => $policies[$drupalField] ?? '',
        ],
      ];
    }

    return $table;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    // Validate the entity that would actually be saved, not the raw input.
    // Structural validation lives on the entity so form validation, update
    // hooks and `drush crm-bridge:doctor` all enforce the same rules.
    $candidate = $this->buildEntity($form, $form_state);
    assert($candidate instanceof CrmBridgeMappingInterface);

    foreach ($candidate->validateStructure() as $problem) {
      $form_state->setErrorByName('fields', $problem);
    }
  }

  /**
   * {@inheritdoc}
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The mapping being built.
   * @param array<string, mixed> $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  protected function copyFormValuesToEntity(
    EntityInterface $entity,
    array $form,
    FormStateInterface $form_state,
  ): void {
    assert($entity instanceof CrmBridgeMappingInterface);

    $entity->set('label', $form_state->getValue('label'));
    $entity->set('id', $form_state->getValue('id'));
    $entity->set('entity_type', $form_state->getValue('entity_type'));
    $entity->set('bundle', (string) $form_state->getValue('bundle'));
    $entity->set('connector', $form_state->getValue('connector'));
    $entity->set('remote_object', $form_state->getValue('remote_object'));
    $entity->set('direction', $form_state->getValue('direction'));

    $entity->set('identity', [
      'strategy' => 'deterministic',
      'keys' => $this->splitList((string) $form_state->getValue('identity_keys')),
      'on_ambiguous' => (string) $form_state->getValue('on_ambiguous'),
    ]);

    $fields = [];
    $perField = [];
    foreach ((array) $form_state->getValue('fields') as $row) {
      $drupalField = trim((string) ($row['drupal'] ?? ''));
      $remoteField = trim((string) ($row['remote'] ?? ''));
      // A row where both sides are blank is a spare row nobody filled in.
      // A row where only one side is blank is a mistake, and is kept so that
      // validation can say so instead of silently discarding the entry.
      if ($drupalField === '' && $remoteField === '') {
        continue;
      }
      $fields[] = [
        'drupal' => $drupalField,
        'remote' => $remoteField,
        'transform' => (string) ($row['transform'] ?? ''),
        'direction' => (string) ($row['direction'] ?? ''),
      ];
      $policy = (string) ($row['policy'] ?? '');
      if ($policy !== '' && $drupalField !== '') {
        $perField[$drupalField] = $policy;
      }
    }

    $entity->set('fields', $fields);
    $entity->set('conflict', [
      'default' => (string) $form_state->getValue('conflict_default'),
      'per_field' => $perField,
    ]);
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return int
   *   Either SAVED_NEW or SAVED_UPDATED.
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Saved the %label mapping.', [
      '%label' => $this->entity->label(),
    ]));
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $result;
  }

  /**
   * Machine name existence callback.
   *
   * @param string $id
   *   The candidate machine name.
   *
   * @return bool
   *   TRUE when a mapping with that ID already exists.
   */
  public function mappingExists(string $id): bool {
    $storage = $this->entityTypeManager->getStorage('crm_bridge_mapping');
    return $storage->load($id) !== NULL;
  }

  /**
   * Splits a comma-separated list into trimmed, non-empty values.
   *
   * @param string $input
   *   The raw input.
   *
   * @return list<string>
   *   The values.
   */
  private function splitList(string $input): array {
    $parts = array_map('trim', explode(',', $input));
    return array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
  }

  /**
   * Lists content entity types that can be mapped.
   *
   * @return array<string, string>
   *   Entity type labels keyed by ID.
   */
  private function contentEntityTypeOptions(): array {
    $options = [];
    foreach ($this->entityTypeManager->getDefinitions() as $id => $definition) {
      // Only content entities are mappable. Syncing a configuration entity to
      // a CRM would mean letting the CRM rewrite site configuration.
      if ($definition->getGroup() !== 'content') {
        continue;
      }
      $options[$id] = (string) $definition->getLabel();
    }
    asort($options);
    return $options;
  }

  /**
   * Direction options.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by direction.
   */
  private function directionOptions(): array {
    return [
      Direction::BIDIRECTIONAL => $this->t('Both ways'),
      Direction::PUSH => $this->t('Drupal to CRM'),
      Direction::PULL => $this->t('CRM to Drupal'),
    ];
  }

  /**
   * Conflict policy options.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by policy.
   */
  private function policyOptions(): array {
    return [
      ConflictPolicy::REVIEW => $this->t('Queue for review (recommended)'),
      ConflictPolicy::DRUPAL_WINS => $this->t('Drupal wins'),
      ConflictPolicy::CRM_WINS => $this->t('CRM wins'),
      ConflictPolicy::NEWEST_WINS => $this->t('Most recently changed wins'),
      ConflictPolicy::FIELD_LEVEL => $this->t('Merge per field'),
    ];
  }

  /**
   * Transform options.
   *
   * @return array<string, string>
   *   Labels keyed by transform.
   */
  private function transformOptions(): array {
    $options = [];
    foreach (Transform::all() as $transform) {
      $options[$transform] = $transform;
    }
    return $options;
  }

}
