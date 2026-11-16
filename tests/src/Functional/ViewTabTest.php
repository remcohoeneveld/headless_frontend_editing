<?php

declare(strict_types=1);

namespace Drupal\Tests\headless_frontend_editing\Functional;

use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\node\Traits\NodeCreationTrait;

/**
 * Tests the decoupled frontend on the node view tab.
 *
 * @group headless_frontend_editing
 * @runTestsInSeparateProcesses
 */
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
    $this->assertStringContainsString('path=' . rawurlencode('/node/1'), $src);
    $this->assertStringContainsString('token=', $src);
    $this->assertStringContainsString('edit=1', $src);
    $this->assertSession()->elementExists('css', '.hfe-editor[hidden][aria-labelledby="hfe-editor-title"]');
    $this->assertSession()->elementExists('css', '.hfe-editor > .hfe-editor__header > h2.hfe-editor__title#hfe-editor-title');
    $this->assertSession()->elementExists('css', '.hfe-editor__header > button.hfe-editor__close[aria-label="Close"][title="Close"] > svg[aria-hidden="true"] > path');
    $this->assertSession()->elementExists('css', '.hfe-editor > iframe.hfe-editor__frame');
    $this->assertSession()->elementExists('css', 'button.hfe-panel__toggle[aria-expanded]');
    $target = 'node:' . $this->node->uuid();
    $edit = Url::fromRoute('headless_frontend_editing.edit', ['langcode' => 'en', 'target' => $target])->toString();
    // The dock: tools, then the menu toggle and its closed menu.
    $this->assertSession()->elementExists('css', '.hfe-panel > .hfe-panel__tools + .hfe-panel__toggle + .hfe-panel__body[hidden]');
    $this->assertSession()->elementExists('css', '.hfe-panel > .hfe-panel__tools > button.hfe-tool.hfe-tool--primary[type="button"][data-hfe-title="Edit page"][data-hfe-open="' . $edit . '"]');
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
    $this->assertSession()->elementNotExists('css', '.hfe-panel__tools');
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
    $this->assertSession()->elementNotExists('css', '.hfe-panel__tools');
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
