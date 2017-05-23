<?php

namespace Drupal\graphql_views\Plugin\Deriver;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Derive fields from configured views.
 */
class ViewDeriver extends DeriverBase implements ContainerDeriverInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The interface plugin manager to search for return type candidates.
   *
   * @var \Drupal\Component\Plugin\PluginManagerInterface
   */
  protected $interfacePluginManager;

  /**
   * An key value pair of data tables and the entities they belong to.
   *
   * @var string[]
   */
  protected $dataTables;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $basePluginId) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('graphql_core.interface_manager')
    );
  }

  /**
   * Creates a ViewDeriver object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   An entity type manager instance.
   * @param \Drupal\Component\Plugin\PluginManagerInterface $interfacePluginManager
   *   The plugin manager for graphql interfaces.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    PluginManagerInterface $interfacePluginManager
  ) {
    $this->interfacePluginManager = $interfacePluginManager;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * Retrieves the entity type id of an entity by its base or data table.
   *
   * @param string $table
   *   The base or data table of an entity.
   *
   * @return string
   *   The id of the entity type that the given base table belongs to.
   */
  protected function getEntityTypeByTable($table) {
    if (!isset($this->dataTables)) {
      $this->dataTables = [];

      foreach ($this->entityTypeManager->getDefinitions() as $entityTypeId => $entityType) {
        if ($dataTable = $entityType->getDataTable()) {
          $this->dataTables[$dataTable] = $entityType->id();
        }
        if ($baseTable = $entityType->getBaseTable()) {
          $this->dataTables[$baseTable] = $entityType->id();
        }
      }
    }

    return !empty($this->dataTables[$table]) ? $this->dataTables[$table] : NULL;
  }

  /**
   * Check if a certain interface exists.
   *
   * @param string $interface
   *   The GraphQL interface name.
   *
   * @return bool
   *   Boolean flag indicating if the interface exists.
   */
  protected function interfaceExists($interface) {
    return (bool) array_filter($this->interfacePluginManager->getDefinitions(), function ($definition) use ($interface) {
      return $definition['name'] === $interface;
    });
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($basePluginDefinition) {
    /** @var \Drupal\views\Entity\View[] $views */
    $views = $this->entityTypeManager->getStorage('view')->loadMultiple();

    foreach ($views as $viewId => $view) {
      if (!$type = $this->getEntityTypeByTable($view->get('base_table'))) {
        continue;
      }

      $typeName = graphql_core_camelcase($type);

      foreach ($view->get('display') as $displayId => $display) {
        if ($display['display_plugin'] !== 'graphql') {
          continue;
        }

        $id = implode('-', [$viewId, $displayId]);
        $name = implode('_', [$viewId, $displayId]);

        $this->derivatives[$id] = [
          'id' => $id,
          'name' => graphql_core_propcase($name) . 'View',
          'type' => $this->interfaceExists($typeName) ? $typeName : 'Entity',
          'view' => $viewId,
          'display' => $displayId,
          'cache_tags' => $view->getCacheTags(),
          'cache_contexts' => $view->getCacheContexts(),
          'cache_max_age' => $view->getCacheMaxAge(),
        ] + $basePluginDefinition;
      }
    }

    return parent::getDerivativeDefinitions($basePluginDefinition);
  }

}