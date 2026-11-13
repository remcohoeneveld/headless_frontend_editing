<?php

declare(strict_types=1);

namespace Drupal\Tests\headless_frontend_editing\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the settings form.
 */
#[Group('headless_frontend_editing')]
#[RunTestsInSeparateProcesses]
final class SettingsFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['headless_frontend_editing'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  private const PATH = 'admin/config/services/headless-frontend-editing';

  /**
   * Tests saving, access and validation.
   */
  public function testSaveSettings(): void {
    $this->drupalLogin($this->drupalCreateUser(['access administration pages']));
    $this->drupalGet(self::PATH);
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($this->drupalCreateUser(['administer headless frontend editing']));
    $this->drupalGet(self::PATH);
    $this->assertSession()->statusCodeEquals(200);

    $this->submitForm(['frontend_url' => 'not a url'], 'Save configuration');
    $this->assertSession()->pageTextContains('The URL not a url is not valid.');

    $this->submitForm(['preview_path' => 'drupal-preview'], 'Save configuration');
    $this->assertSession()->pageTextContains('The path must start with a slash.');
    $this->submitForm(['preview_path' => '/drupal-preview', 'revalidate_path' => 'api/revalidate'], 'Save configuration');
    $this->assertSession()->pageTextContains('The path must start with a slash.');

    $this->submitForm(['frontend_url' => 'https://front.example', 'revalidate_path' => ''], 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration options have been saved.');
    $this->assertSame('https://front.example', $this->config('headless_frontend_editing.settings')->get('frontend_url'));
  }

}
