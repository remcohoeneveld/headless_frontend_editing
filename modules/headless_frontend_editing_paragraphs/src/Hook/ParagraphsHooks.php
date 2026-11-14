<?php

declare(strict_types=1);

namespace Drupal\headless_frontend_editing_paragraphs\Hook;

use Drupal\Component\Utility\Html;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\TypedData\TranslatableInterface;
use Drupal\headless_frontend_editing\Hook\EntityFormHooks;
use Drupal\headless_frontend_editing\Target;
use Drupal\headless_frontend_editing_paragraphs\Form\ParagraphForm;
use Drupal\headless_frontend_editing_paragraphs\ParagraphTree;
use Drupal\paragraphs\ParagraphInterface;

/**
 * Section actions for paragraphs in the frontend preview.
 */
final class ParagraphsHooks {

  use StringTranslationTrait;

  public function __construct(
    private readonly ParagraphTree $tree,
    private readonly ModuleExtensionList $moduleList,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * Implements hook_entity_type_alter().
   *
   * Paragraphs edited in the sidebar are saved through the page they are on.
   *
   * @param array<string, \Drupal\Core\Entity\EntityTypeInterface> $entity_types
   *   The entity types.
   */
  #[Hook('entity_type_alter')]
  public function entityTypeAlter(array &$entity_types): void {
    if (isset($entity_types['paragraph'])) {
      $entity_types['paragraph']->setFormClass(EntityFormHooks::OPERATION, ParagraphForm::class);
    }
  }

  /**
   * Implements hook_library_info_alter().
   *
   * The section actions load wherever the editing host does.
   *
   * @param array<string, mixed> $libraries
   *   The libraries of the extension.
   * @param string $extension
   *   The extension.
   */
  #[Hook('library_info_alter')]
  public function libraryInfoAlter(array &$libraries, string $extension): void {
    if ($extension === 'headless_frontend_editing' && isset($libraries['host'])) {
      $libraries['host']['js']['/' . $this->moduleList->getPath('headless_frontend_editing_paragraphs') . '/js/actions.js'] = [];
    }
  }

  /**
   * Implements hook_headless_frontend_editing_preview_root().
   */
  #[Hook('headless_frontend_editing_preview_root')]
  public function previewRoot(ContentEntityInterface $entity, FormStateInterface $form_state): ?ContentEntityInterface {
    return $entity instanceof ParagraphInterface ? $this->tree->attach($entity, $form_state->get('hfe_insert')) : NULL;
  }

  /**
   * Implements hook_headless_frontend_editing_target_access().
   *
   * A paragraph is edited through the page it is on: that needs access to the
   * root and to the section field of every parent. Paragraphs that are no
   * longer referenced are refused.
   */
  #[Hook('headless_frontend_editing_target_access')]
  public function targetAccess(Target $target, AccountInterface $account): AccessResultInterface {
    if (!$target->entity instanceof ParagraphInterface) {
      return AccessResult::neutral();
    }
    $paragraph = $this->tree->load($target->entity->uuid());
    return $paragraph ? $this->tree->access($paragraph, $account) : AccessResult::forbidden()->setCacheMaxAge(0);
  }

  /**
   * Implements hook_headless_frontend_editing_target_alter().
   *
   * Adds moving, deleting and adding sections to paragraph targets.
   *
   * @param array<string, mixed> $description
   *   The target description.
   * @param \Drupal\headless_frontend_editing\Target $target
   *   The target.
   */
  #[Hook('headless_frontend_editing_target_alter')]
  public function targetAlter(array &$description, Target $target): void {
    $paragraph = $target->entity;
    if ($target->fieldName !== NULL || !$paragraph instanceof ParagraphInterface || !$this->tree->load($paragraph->uuid())) {
      return;
    }
    // The paragraph's own label names its parent and field; the type says
    // what the section is.
    $type = (string) $paragraph->getParagraphType()->label();
    $description['label'] = $type;
    $description['actions'][0]['label'] = Html::decodeEntities((string) $this->t('Edit @section', ['@section' => $type]));
    // Describe only gets here once targetAccess() allowed the paragraph.
    if (!$this->canRearrange($paragraph)) {
      return;
    }
    $items = $this->tree->items($paragraph);
    $count = count($items);
    $delta = $this->tree->delta($items, $paragraph);
    $full = !$this->tree->allowedTypes($paragraph);
    foreach ([
      'up' => [$this->t('Move up'), $delta === 0],
      'down' => [$this->t('Move down'), $delta === $count - 1],
      'delete' => [$this->t('Delete'), $count < 2 || !$paragraph->access('delete', $this->currentUser)],
      'add-before' => [$this->t('Add section above'), $full],
      'add-after' => [$this->t('Add section below'), $full],
    ] as $id => [$label, $disabled]) {
      $description['actions'][] = ['id' => $id, 'label' => (string) $label, 'disabled' => $disabled];
    }
  }

  /**
   * Whether sections may be added, moved or deleted next to this one.
   *
   * Adding, moving or deleting changes the reference field of every parent up
   * to the root. When one of them is not translatable, that change reaches all
   * languages, so it is only offered in the root's default translation.
   */
  private function canRearrange(ParagraphInterface $paragraph): bool {
    $entity = $paragraph;
    while ($entity instanceof ParagraphInterface && ($items = $this->tree->items($entity))) {
      // Not $items->getEntity(): untranslatable fields belong to the default
      // translation.
      $parent = $entity->getParentEntity();
      if (!$items->getFieldDefinition()->isTranslatable() && $parent instanceof TranslatableInterface && !$parent->isDefaultTranslation()) {
        return FALSE;
      }
      $entity = $parent;
    }
    return TRUE;
  }

}
