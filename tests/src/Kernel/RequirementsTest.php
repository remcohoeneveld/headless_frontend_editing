<?php

declare(strict_types=1);

namespace Drupal\Tests\headless_frontend_editing\Kernel;

use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests the status report entry.
 *
 * @group headless_frontend_editing
 * @runTestsInSeparateProcesses
 */
final class RequirementsTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['headless_frontend_editing'];

  /**
   * Tests warning when unconfigured and OK when configured.
   */
  public function testWarnings(): void {
    $this->installConfig(['headless_frontend_editing']);
    $requirements = $this->getRequirements();
    $this->assertSame(REQUIREMENT_WARNING, $requirements['headless_frontend_editing']['severity']);

    $this->config('headless_frontend_editing.settings')
      ->set('frontend_url', 'https://front.example')
      ->set('frontend_internal_url', 'http://frontend:3000')
      ->save();
    new Settings(['headless_frontend_editing_revalidate_secret' => 'test-secret'] + Settings::getAll());
    $requirements = $this->getRequirements();
    $this->assertSame(REQUIREMENT_OK, $requirements['headless_frontend_editing']['severity']);
  }

  /**
   * Collects runtime requirements.
   *
   * @return array<string, array<string, mixed>>
   *   The requirements.
   */
  private function getRequirements(): array {
    \Drupal::moduleHandler()->loadInclude('headless_frontend_editing', 'install');
    require_once DRUPAL_ROOT . '/core/includes/install.inc';
    return headless_frontend_editing_requirements('runtime');
  }

}
