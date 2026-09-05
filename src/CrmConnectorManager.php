<?php

declare(strict_types=1);

namespace Drupal\crm_bridge;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\crm_bridge\Annotation\CrmConnector;

/**
 * Discovers CRM connector plugins.
 */
class CrmConnectorManager extends DefaultPluginManager {

  /**
   * Constructs the manager.
   *
   * @param \Traversable<string, string> $namespaces
   *   Keyed by namespace, valued by directory.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   The cache backend.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cache_backend,
    ModuleHandlerInterface $module_handler,
  ) {
    parent::__construct(
      'Plugin/CrmConnector',
      $namespaces,
      $module_handler,
      CrmConnectorInterface::class,
      CrmConnector::class,
    );
    $this->alterInfo('crm_bridge_connector_info');
    $this->setCacheBackend($cache_backend, 'crm_bridge_connector_plugins');
  }

  /**
   * Instantiates a connector.
   *
   * @param string $id
   *   The plugin ID.
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   *
   * @return \Drupal\crm_bridge\CrmConnectorInterface
   *   The connector.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   *   When no connector with that ID exists.
   */
  public function connector(string $id, array $configuration = []): CrmConnectorInterface {
    $instance = $this->createInstance($id, $configuration);
    assert($instance instanceof CrmConnectorInterface);
    return $instance;
  }

}
