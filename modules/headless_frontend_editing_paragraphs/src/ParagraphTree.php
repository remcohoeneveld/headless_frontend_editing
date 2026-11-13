<?php

declare(strict_types=1);

namespace Drupal\headless_frontend_editing_paragraphs;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\paragraphs\ParagraphInterface;

/**
 * Finds, places and saves paragraphs in the entity they are (nested) on.
 *
 * Changes are made in memory and saved through the root entity, so every
 * change is one new revision of the page, also for nested paragraphs.
 */
final class ParagraphTree {

  use StringTranslationTrait;

  public function __construct(
    private readonly EntityRepositoryInterface $entityRepository,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountInterface $currentUser,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Loads a paragraph that its parents still reference, or returns NULL.
   */
  public function load(string $uuid): ?ParagraphInterface {
    $paragraph = $this->entityRepository->loadEntityByUuid('paragraph', $uuid);
    if (!$paragraph instanceof ParagraphInterface) {
      return NULL;
    }
    $entity = $paragraph;
    while ($entity instanceof ParagraphInterface) {
      $items = $this->items($entity);
      if (!$items) {
        return NULL;
      }
      try {
        $this->delta($items, $entity);
      }
      catch (\LogicException) {
        return NULL;
      }
      $entity = $entity->getParentEntity();
    }
    return $paragraph;
  }

  /**
   * Checks access to add, move or delete sections next to a paragraph.
   *
   * That changes the reference field of every parent up to the root, so it
   * needs update access to the root and edit access to each of those fields.
   * A root with a pending draft is refused: the change would be saved on the
   * default revision and the draft lost.
   */
  public function access(ParagraphInterface $paragraph, AccountInterface $account): AccessResultInterface {
    $root = $this->root($paragraph);
    if (!$root || !$root->isLatestRevision()) {
      return AccessResult::forbidden()->setCacheMaxAge(0);
    }
    $result = $root->access('update', $account, TRUE);
    $entity = $paragraph;
    while ($entity instanceof ParagraphInterface && ($items = $this->items($entity))) {
      $result = $result->andIf($items->access('edit', $account, TRUE));
      $entity = $entity->getParentEntity();
    }
    return $result;
  }

  /**
   * Returns the field on the parent that lists the paragraph and its siblings.
   *
   * @return \Drupal\Core\Field\FieldItemListInterface<\Drupal\Core\Field\FieldItemInterface>|null
   *   The items, or NULL for a paragraph without parent.
   */
  public function items(ParagraphInterface $paragraph): ?FieldItemListInterface {
    $parent = $paragraph->getParentEntity();
    $field_name = $paragraph->get('parent_field_name')->value;
    return $parent instanceof ContentEntityInterface && $field_name && $parent->hasField($field_name) ? $parent->get($field_name) : NULL;
  }

  /**
   * Returns the non-paragraph entity a possibly nested paragraph is on.
   */
  public function root(ParagraphInterface $paragraph): ?ContentEntityInterface {
    $entity = $paragraph->getParentEntity();
    while ($entity instanceof ParagraphInterface) {
      $entity = $entity->getParentEntity();
    }
    return $entity instanceof ContentEntityInterface ? $entity : NULL;
  }

  /**
   * Places a changed or new paragraph in its parents, in memory.
   *
   * Nothing is saved: saving the returned root saves the paragraphs with it.
   *
   * @param \Drupal\paragraphs\ParagraphInterface $paragraph
   *   The changed or new paragraph.
   * @param array{\Drupal\paragraphs\ParagraphInterface, string}|null $insert
   *   For a new paragraph: the paragraph to insert it next to, and 'before'
   *   or 'after'.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|null
   *   The root entity, or NULL if a parent is missing or no longer
   *   references the paragraph.
   */
  public function attach(ParagraphInterface $paragraph, ?array $insert = NULL): ?ContentEntityInterface {
    [$anchor, $position] = $insert ?? [$paragraph, NULL];
    $items = $this->items($anchor);
    try {
      $delta = $items ? $this->delta($items, $anchor) : NULL;
    }
    catch (\LogicException) {
      $delta = NULL;
    }
    if ($delta === NULL) {
      return NULL;
    }
    if ($insert) {
      $values = $items->getValue();
      array_splice($values, $delta + ($position === 'after' ? 1 : 0), 0, [['entity' => $paragraph]]);
      $items->setValue($values);
      $paragraph->setParentEntity($anchor->getParentEntity(), $items->getName());
    }
    else {
      $items->get($delta)->set('entity', $paragraph);
    }
    // Keeps the in-memory changes when the root is serialized for a preview.
    $paragraph->setNeedsSave(TRUE);
    // Not $items->getEntity(): untranslatable fields belong to the default
    // translation.
    $parent = $anchor->getParentEntity();
    return $parent instanceof ParagraphInterface ? $this->attach($parent) : $parent;
  }

  /**
   * Returns the position of a paragraph in its parent field.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface<\Drupal\Core\Field\FieldItemInterface> $items
   *   The parent field.
   * @param \Drupal\paragraphs\ParagraphInterface $paragraph
   *   The paragraph.
   *
   * @throws \LogicException
   *   When the field does not reference the paragraph.
   */
  public function delta(FieldItemListInterface $items, ParagraphInterface $paragraph): int {
    foreach ($items->getValue() as $delta => $value) {
      if ((int) ($value['target_id'] ?? 0) === (int) $paragraph->id()) {
        return $delta;
      }
    }
    throw new \LogicException('The paragraph is not referenced by its parent field.');
  }

  /**
   * Swaps a paragraph with its neighbour and saves the root.
   *
   * @return bool
   *   FALSE when there is no neighbour in that direction.
   */
  public function move(ParagraphInterface $paragraph, string $direction): bool {
    $items = $this->items($paragraph) ?? throw new \LogicException('The paragraph has no parent.');
    $values = $items->getValue();
    $from = $this->delta($items, $paragraph);
    $to = $direction === 'up' ? $from - 1 : $from + 1;
    if (!isset($values[$to])) {
      return FALSE;
    }
    [$values[$from], $values[$to]] = [$values[$to], $values[$from]];
    $items->setValue($values);
    $parent = $paragraph->getParentEntity();
    $root = $parent instanceof ParagraphInterface ? $this->attach($parent) : $parent;
    $this->saveRoot($root, (string) $this->t('Moved @section from the frontend preview.', ['@section' => $paragraph->getParagraphType()->label()]));
    return TRUE;
  }

  /**
   * Returns the paragraph types that can be added next to a paragraph.
   *
   * @return \Drupal\paragraphs\ParagraphsTypeInterface[]
   *   The types the parent field allows, keyed by id; none when it is full.
   */
  public function allowedTypes(ParagraphInterface $anchor): array {
    $items = $this->items($anchor);
    $definition = $items?->getFieldDefinition();
    $cardinality = $definition?->getFieldStorageDefinition()->getCardinality();
    if (!$definition || ($cardinality !== FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED && count($items) >= $cardinality)) {
      return [];
    }
    $settings = $definition->getSetting('handler_settings') ?: [];
    $bundles = $settings['target_bundles'] ?? NULL;
    /** @var \Drupal\paragraphs\ParagraphsTypeInterface[] $types */
    $types = $this->entityTypeManager->getStorage('paragraphs_type')->loadMultiple();
    if (!$bundles) {
      return $types;
    }
    // With negate, target_bundles lists the types the field excludes.
    return empty($settings['negate']) ? array_intersect_key($types, $bundles) : array_diff_key($types, $bundles);
  }

  /**
   * Saves the root entity as a new revision with a log message.
   */
  public function saveRoot(ContentEntityInterface $root, string $message): void {
    if ($root->getEntityType()->isRevisionable()) {
      $root->setNewRevision();
    }
    if ($root instanceof RevisionLogInterface) {
      $root->setRevisionUserId((int) $this->currentUser->id());
      $root->setRevisionCreationTime($this->time->getRequestTime());
      $root->setRevisionLogMessage($message);
    }
    $root->save();
  }

}
