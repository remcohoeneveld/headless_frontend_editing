<?php

declare(strict_types=1);

namespace Drupal\headless_frontend_editing_paragraphs_test\Hook;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;

/**
 * Denies creating the paragraph type, or deleting paragraphs, named in state.
 */
final class ParagraphAccessHooks {

  public const NO_CREATE = 'headless_frontend_editing_paragraphs_test.no_create';

  public const NO_DELETE = 'headless_frontend_editing_paragraphs_test.no_delete';

  public function __construct(
    private readonly StateInterface $state,
  ) {}

  /**
   * Implements hook_paragraph_create_access().
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user.
   * @param array<string, mixed> $context
   *   The context.
   * @param string|null $bundle
   *   The paragraph type.
   */
  public function createAccess(AccountInterface $account, array $context, ?string $bundle): AccessResult {
    return AccessResult::forbiddenIf($bundle === $this->state->get(self::NO_CREATE))->setCacheMaxAge(0);
  }

  /**
   * Implements hook_paragraph_access().
   */
  public function access(EntityInterface $paragraph, string $operation, AccountInterface $account): AccessResult {
    return AccessResult::forbiddenIf($operation === 'delete' && $this->state->get(self::NO_DELETE))->setCacheMaxAge(0);
  }

}
