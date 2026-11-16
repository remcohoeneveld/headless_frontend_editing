<?php

declare(strict_types=1);

namespace Drupal\headless_frontend_editing\Hook;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * Help text.
 */
final class HelpHooks {

  use StringTranslationTrait;

  /**
   * Implements hook_help().
   */
  public function help(string $route_name, RouteMatchInterface $route_match): ?string {
    return match ($route_name) {
      'help.page.headless_frontend_editing' => '<p>' . $this->t('In-place editing for decoupled frontends. Configure the frontend URL and the internal URL on the <a href=":url">settings page</a>. The shared revalidation secret is set in settings.php as $settings["headless_frontend_editing_revalidate_secret"].', [':url' => Url::fromRoute('headless_frontend_editing.settings')->toString()]) . '</p>',
      default => NULL,
    };
  }

}
