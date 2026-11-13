<?php

declare(strict_types=1);

namespace Drupal\headless_frontend_editing;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Turns a target string into the entity (translation) and field it names.
 */
final class TargetResolver {

  public const PATTERN = '/^([a-z_]+):([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})(?::([a-z0-9_]+))?$/';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityRepositoryInterface $entityRepository,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Resolves a target string, or returns NULL when it names nothing editable.
   */
  public function resolve(string $target, string $langcode): ?Target {
    if (!preg_match(self::PATTERN, $target, $m)) {
      return NULL;
    }
    $field = $m[3] ?? NULL;
    // loadEntityByUuid() throws for types without a uuid key.
    $definition = $this->entityTypeManager->getDefinition($m[1], FALSE);
    if (!$definition instanceof ContentEntityTypeInterface || !$definition->hasKey('uuid')) {
      return NULL;
    }
    $entity = $this->entityRepository->loadEntityByUuid($m[1], $m[2]);
    if (!$entity instanceof ContentEntityInterface) {
      return NULL;
    }
    // Entities that cannot be translated (or have a locked language such as
    // und or zxx) are used as they are, whatever language was asked for.
    if ($entity->isTranslatable()) {
      if (!$entity->hasTranslation($langcode)) {
        return NULL;
      }
      $entity = $entity->getTranslation($langcode);
    }
    return $field === NULL || $entity->hasField($field) ? new Target($entity, $field) : NULL;
  }

  /**
   * Checks whether an account may edit a target.
   *
   * Needs update access to the entity and edit access to the field, no
   * pending draft (the form edits the default revision, so saving would lose
   * the draft) and no forbidden result from
   * hook_headless_frontend_editing_target_access(). Composite entities, such
   * as paragraphs, are saved through their host, so they also need a module
   * that knows the host to allow them.
   */
  public function access(Target $target, AccountInterface $account): AccessResultInterface {
    $entity = $target->entity;
    $result = $entity->access('update', $account, TRUE);
    if ($target->fieldName !== NULL) {
      $result = $result->andIf($entity->get($target->fieldName)->access('edit', $account, TRUE));
    }
    $hooks = AccessResult::neutral();
    foreach ($this->moduleHandler->invokeAll('headless_frontend_editing_target_access', [$target, $account]) as $hook) {
      $hooks = $hooks->orIf($hook);
    }
    $composite = (bool) $entity->getEntityType()->get('entity_revision_parent_type_field');
    if (!$result->isAllowed() || $hooks->isForbidden() || ($composite && !$hooks->isAllowed()) || !$entity->isLatestRevision()) {
      return AccessResult::forbidden()->addCacheableDependency($result)->addCacheableDependency($hooks)->setCacheMaxAge(0);
    }
    return AccessResult::allowed()->addCacheableDependency($result)->addCacheableDependency($hooks);
  }

}
