<?php

declare(strict_types=1);

namespace Drupal\Tests\headless_frontend_editing\Functional;

use Drupal\node\NodeInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\node\Traits\NodeCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the decoupled frontend on the node view tab.
 */
#[Group('headless_frontend_editing')]
#[RunTestsInSeparateProcesses]
final class ViewTabTest extends BrowserTestBase {

  use NodeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'headless_frontend_editing'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The node under test.
   */
  private NodeInterface $node;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalCreateContentType(['type' => 'article']);
    $this->drupalCreateContentType(['type' => 'page']);
    $this->config('headless_frontend_editing.settings')->set('frontend_url', 'https://front.example')->save();
    $this->node = $this->drupalCreateNode(['type' => 'article', 'title' => 'Hello world']);
  }

  /**
   * Returns the preview iframe src.
   */
  private function iframeSrc(): string {
    return $this->assertSession()->elementExists('css', 'iframe.hfe-preview')->getAttribute('src');
  }

  /**
   * Editors get a tokenized, editable preview.
   */
  public function testEditorGetsEditablePreview(): void {
    $this->drupalLogin($this->drupalCreateUser(['use headless frontend editing', 'bypass node access', 'access content']));
    $this->drupalGet('node/1');
    $src = $this->iframeSrc();
    $this->assertStringStartsWith('https://front.example/drupal-preview?', $src);
    $this->assertStringContainsString('path=%2Fnode%2F1', $src);
    $this->assertStringContainsString('token=', $src);
    $this->assertStringContainsString('edit=1', $src);
    $this->assertSession()->elementExists('css', '.hfe-editor[hidden]');
    $this->assertSession()->elementExists('css', 'button.hfe-panel__toggle[aria-expanded]');
    $this->assertSession()->pageTextContains('Open on website');
    $this->assertSession()->responseContains('"headlessFrontendEditing":{"frontendOrigin":"https:\/\/front.example"');
  }

  /**
   * Anonymous visitors get the live URL.
   */
  public function testAnonymousGetsLiveUrl(): void {
    user_role_grant_permissions('anonymous', ['access content']);
    $this->drupalGet('node/1');
    $this->assertSame('https://front.example/node/1', $this->iframeSrc());
    $this->assertSession()->elementNotExists('css', '.hfe-editor');
  }

  /**
   * Revisions are read-only.
   */
  public function testRevisionIsReadOnly(): void {
    $this->drupalLogin($this->drupalCreateUser([
      'use headless frontend editing',
      'bypass node access',
      'view all revisions',
      'access content',
    ]));
    $vid = (int) $this->node->getRevisionId();
    $this->drupalGet("node/1/revisions/$vid/view");
    $src = $this->iframeSrc();
    $this->assertStringContainsString("revision=$vid", $src);
    $this->assertStringNotContainsString('edit=1', $src);
  }

  /**
   * Without the module permission, revisions keep core's render.
   */
  public function testRevisionWithoutPermission(): void {
    $this->node->setTitle('Second')->setNewRevision();
    $this->node->save();
    $this->drupalLogin($this->drupalCreateUser(['bypass node access', 'view all revisions', 'access content']));
    $vid = (int) $this->node->getRevisionId();
    $this->drupalGet("node/1/revisions/$vid/view");
    $this->assertSession()->elementNotExists('css', 'iframe.hfe-preview');
    $this->assertSession()->pageTextContains('Second');
  }

  /**
   * Bundles outside the configured list keep core's render.
   */
  public function testBundleNotConfigured(): void {
    $this->config('headless_frontend_editing.settings')->set('bundles', ['node' => ['page']])->save();
    $this->drupalLogin($this->drupalCreateUser(['use headless frontend editing', 'access content']));
    $this->drupalGet('node/1');
    $this->assertSession()->elementNotExists('css', 'iframe.hfe-preview');
    $this->assertSession()->pageTextContains('Hello world');
  }

  /**
   * Without a frontend URL administrators see the configuration message.
   */
  public function testNoFrontendUrl(): void {
    $this->config('headless_frontend_editing.settings')->set('frontend_url', '')->save();
    $this->drupalLogin($this->drupalCreateUser(['administer headless frontend editing', 'access content']));
    $this->drupalGet('node/1');
    $this->assertSession()->pageTextContains('Set the frontend URL');
    $this->assertSession()->elementNotExists('css', 'iframe.hfe-preview');
  }

}
