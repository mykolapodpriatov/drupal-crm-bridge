<?php

declare(strict_types=1);

namespace Drupal\crm_bridge\Annotation;

use Drupal\Component\Annotation\Plugin;

/**
 * Declares a CRM connector plugin.
 *
 * An annotation rather than a PHP attribute, for the same reason the mapping
 * entity uses one: this module supports Drupal 10.3, and a single discovery
 * mechanism that works on both versions is worth more than a modern one that
 * works on half the matrix. It can become an attribute when 10.3 is dropped.
 *
 * @Annotation
 */
class CrmConnector extends Plugin {

  /**
   * The plugin ID.
   *
   * @var string
   */
  public string $id;

  /**
   * The human-readable label.
   *
   * @var \Drupal\Core\Annotation\Translation
   *
   * @ingroup plugin_translatable
   */
  public $label;

  /**
   * A one-line description of what this connector talks to.
   *
   * @var \Drupal\Core\Annotation\Translation
   *
   * @ingroup plugin_translatable
   */
  public $description;

}
