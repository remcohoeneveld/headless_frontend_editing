<?php

declare(strict_types=1);

namespace Drupal\headless_frontend_editing_paragraphs_test\Hook;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\headless_frontend_editing\Target;

/**
 * Locks the target whose id is in the state key of this module.
 */
final class LockedTargetHooks {

  public const STATE = 'headless_frontend_editing_paragraphs_test.locked_target';

  public function __construct(
    private readonly StateInterface $state,
  ) {}

  /**
   * Implements hook_headless_frontend_editing_target_access().
   */
  public function targetAccess(Target $target, AccountInterface $account): AccessResultInterface {
    return AccessResult::forbiddenIf($target->id() === $this->state->get(self::STATE))->setCacheMaxAge(0);
  }

}
