<?php

declare(strict_types=1);

namespace Drupal\headless_frontend_editing\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Status report entry for the headless frontend connection.
 */
final class RequirementsHooks {

  use StringTranslationTrait;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Implements hook_runtime_requirements().
   *
   * @return array<string, array<string, mixed>>
   *   The requirements.
   */
  #[Hook('runtime_requirements')]
  public function runtime(): array {
    $config = $this->configFactory->get('headless_frontend_editing.settings');
    $missing = [];
    if (!$config->get('frontend_url')) {
      $missing[] = $this->t('the frontend URL');
    }
    if (!$config->get('frontend_internal_url')) {
      $missing[] = $this->t('the frontend internal URL');
    }
    if (!Settings::get('headless_frontend_editing_revalidate_secret')) {
      $missing[] = $this->t('$settings["headless_frontend_editing_revalidate_secret"]');
    }

    return [
      'headless_frontend_editing' => [
        'title' => $this->t('Headless frontend editing'),
        'severity' => $missing ? RequirementSeverity::Warning : RequirementSeverity::OK,
        'value' => $missing ? $this->t('Not fully configured') : $this->t('Configured'),
        'description' => $missing ? $this->t('Missing: @missing.', ['@missing' => implode(', ', $missing)]) : '',
      ],
    ];
  }

}
