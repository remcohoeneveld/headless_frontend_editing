<?php

declare(strict_types=1);

namespace Drupal\headless_frontend_editing;

use Drupal\Component\Utility\Html;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Turns a target string into the entity (translation) and field it names.
 */
final class TargetResolver {

  use StringTranslationTrait;

  public const PATTERN = '/^([a-z_]+):([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})(?::([a-z0-9_]+))?$/';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityRepositoryInterface $entityRepository,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly EntityTypeBundleInfoInterface $bundleInfo,
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

  /**
   * Describes a target: its label and actions, as describe returns them.
   *
   * Runs hook_headless_frontend_editing_target_alter(). Does not check
   * access.
   *
   * @return array<string, mixed>
   *   The description: id, lang, label and actions (each with id, label and
   *   disabled), all plain text.
   */
  public function describe(Target $target, string $lang): array {
    $entity = $target->entity;
    if ($target->fieldName === NULL) {
      $bundle = $this->bundleInfo->getBundleInfo($entity->getEntityTypeId())[$entity->bundle()]['label'] ?? $entity->bundle();
      $name = (string) $entity->label();
      $label = $this->t('@label (@bundle)', ['@label' => $name, '@bundle' => $bundle]);
      $action = $this->t('Edit @label', ['@label' => $name]);
    }
    else {
      $label = $entity->get($target->fieldName)->getFieldDefinition()->getLabel();
      $action = $this->t('Edit @label', ['@label' => $label]);
    }
    $description = [
      'id' => $target->id(),
      'lang' => $lang,
      // Plain text: the client sets it with textContent, so undo the escaping
      // of the @ placeholders.
      'label' => Html::decodeEntities((string) $label),
      'actions' => [['id' => 'edit', 'label' => Html::decodeEntities((string) $action), 'disabled' => FALSE]],
    ];
    $this->moduleHandler->alter('headless_frontend_editing_target', $description, $target);
    return $description;
  }

}
