<?php

declare(strict_types=1);

namespace Drupal\headless_frontend_editing;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Turns a target string into the entity (translation) and field it names.
 */
final class TargetResolver {

  public const PATTERN = '/^([a-z_]+):([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})(?::([a-z0-9_]+))?$/';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityRepositoryInterface $entityRepository,
  ) {}

  /**
   * Resolves a target string, or returns NULL when it names nothing editable.
   */
  public function resolve(string $target, string $langcode): ?Target {
    if (!preg_match(self::PATTERN, $target, $m)) {
      return NULL;
    }
    $field = $m[3] ?? NULL;
    if (!($this->entityTypeManager->getDefinition($m[1], FALSE) instanceof ContentEntityTypeInterface)) {
      return NULL;
    }
    $entity = $this->entityRepository->loadEntityByUuid($m[1], $m[2]);
    if (!$entity instanceof ContentEntityInterface || !$entity->hasTranslation($langcode)) {
      return NULL;
    }
    $entity = $entity->getTranslation($langcode);
    return $field === NULL || $entity->hasField($field) ? new Target($entity, $field) : NULL;
  }

}
