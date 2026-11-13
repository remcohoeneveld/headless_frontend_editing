<?php

declare(strict_types=1);

namespace Drupal\headless_frontend_editing\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Help text.
 */
final class HelpHooks {

  use StringTranslationTrait;

  /**
   * Implements hook_help().
   */
  #[Hook('help')]
  public function help(string $route_name, RouteMatchInterface $route_match): ?string {
    return match ($route_name) {
      'help.page.headless_frontend_editing' => '<p>' . $this->t('In-place editing for decoupled frontends. Configure the frontend URL, the internal URL and the shared revalidation secret on the <a href=":url">settings page</a>.', [':url' => '/admin/config/services/headless-frontend-editing']) . '</p>',
      default => NULL,
    };
  }

}
