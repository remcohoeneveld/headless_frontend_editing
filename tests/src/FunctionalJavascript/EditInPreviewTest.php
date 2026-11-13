<?php

declare(strict_types=1);

namespace Drupal\Tests\headless_frontend_editing\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the overlay client and the editing protocol in a browser.
 */
#[Group('headless_frontend_editing')]
#[RunTestsInSeparateProcesses]
final class EditInPreviewTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'headless_frontend_editing', 'headless_frontend_editing_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The article shown in the preview.
   */
  private NodeInterface $node;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalCreateContentType(['type' => 'article']);
    $this->node = $this->drupalCreateNode(['type' => 'article', 'title' => 'Original']);
    // The test module serves the frontend from Drupal itself.
    $this->config('headless_frontend_editing.settings')
      ->set('frontend_url', $this->baseUrl)
      ->set('preview_path', '/hfe-test/frontend')
      ->save();
    $this->drupalLogin($this->drupalCreateUser([
      'access content',
      'edit any article content',
      'use headless frontend editing',
    ]));
  }

  /**
   * Opens the node page and hovers the title in the preview.
   *
   * Leaves the session inside the preview iframe.
   */
  private function hoverTitle(): void {
    $this->drupalGet($this->node->toUrl());
    $this->enterFrame('hfe-preview');
    $this->assertSession()->waitForElement('css', 'h1')->mouseOver();
    $this->assertTrue($this->getSession()->wait(5000, "(() => { const toolbar = document.querySelector('[data-hfe-overlay]')?.shadowRoot.querySelector('.toolbar'); return !!toolbar && !toolbar.hidden && !!toolbar.querySelector('button'); })()"));
  }

  /**
   * Switches the session into the iframe with the given class.
   */
  private function enterFrame(string $class): void {
    $this->getSession()->switchToIFrame();
    $this->assertSession()->waitForElement('css', "iframe.$class");
    $this->getSession()->executeScript("document.querySelector('iframe.$class').name = '$class';");
    $this->getSession()->switchToIFrame($class);
  }

  /**
   * Returns a query parameter of the preview iframe's current URL.
   */
  private function previewParam(string $name): ?string {
    return $this->getSession()->evaluateScript("new URL(document.querySelector('.hfe-preview').contentWindow.location.href).searchParams.get('$name')");
  }

  /**
   * Tests that hovering a target shows its toolbar with labels from Drupal.
   */
  public function testHoverShowsToolbar(): void {
    $this->hoverTitle();
    $toolbar = $this->getSession()->evaluateScript("[...document.querySelector('[data-hfe-overlay]').shadowRoot.querySelector('.toolbar').children].map((el) => el.textContent)");
    $this->assertSame(['Title', 'Edit Title'], $toolbar);
  }

  /**
   * Tests editing in the sidebar, the live preview and saving.
   */
  public function testEditLivePreviewSave(): void {
    $this->hoverTitle();
    $this->getSession()->executeScript("document.querySelector('[data-hfe-overlay]').shadowRoot.querySelector('button').click()");
    $this->getSession()->switchToIFrame();
    $this->assertNotNull($this->assertSession()->waitForElementVisible('css', '.hfe-editor'));
    $initial_token = $this->previewParam('token');
    $this->assertNotEmpty($initial_token);

    // Tokens carry their expiry in seconds; let the next one differ.
    sleep(1);
    $this->enterFrame('hfe-editor__frame');
    $this->assertSession()->waitForField('title[0][value]')->setValue('Live title');
    $this->getSession()->switchToIFrame();
    $this->assertTrue($this->getSession()->wait(5000, "/^\\/node\\/preview\\//.test(new URL(document.querySelector('.hfe-preview').contentWindow.location.href).searchParams.get('path') || '')"));
    $this->assertNotEmpty($this->previewParam('token'));
    $this->assertNotSame($initial_token, $this->previewParam('token'));
    $this->assertTrue($this->getSession()->wait(5000, "document.querySelector('.hfe-preview').contentDocument.querySelector('h1')?.textContent === 'Live title'"));

    $this->getSession()->executeScript('window.hfeBeforeReload = true;');
    $this->enterFrame('hfe-editor__frame');
    $this->getSession()->getPage()->pressButton('Save');
    $this->getSession()->switchToIFrame();
    $this->assertTrue($this->getSession()->wait(10000, "!window.hfeBeforeReload && document.querySelector('.hfe-preview')?.contentDocument?.querySelector('h1')?.textContent === 'Live title'"));
    $this->assertSame('/node/' . $this->node->id(), $this->previewParam('path'));
    $this->assertTrue($this->assertSession()->elementExists('css', '.hfe-editor')->hasAttribute('hidden'));
  }

  /**
   * Tests that closing the sidebar after a live preview restores the page.
   */
  public function testCloseRestoresSavedPath(): void {
    $this->hoverTitle();
    $this->getSession()->executeScript("document.querySelector('[data-hfe-overlay]').shadowRoot.querySelector('button').click()");
    $this->enterFrame('hfe-editor__frame');
    $this->assertSession()->waitForField('title[0][value]')->setValue('Unsaved title');
    $this->getSession()->switchToIFrame();
    $this->assertTrue($this->getSession()->wait(5000, "document.querySelector('.hfe-preview').contentDocument.querySelector('h1')?.textContent === 'Unsaved title'"));

    $this->getSession()->getPage()->pressButton('Close');
    $this->assertTrue($this->assertSession()->elementExists('css', '.hfe-editor')->hasAttribute('hidden'));
    $this->assertTrue($this->getSession()->wait(5000, "document.querySelector('.hfe-preview').contentDocument.querySelector('h1')?.textContent === 'Original'"));
    $this->assertSame('/node/' . $this->node->id(), $this->previewParam('path'));
  }

  /**
   * Tests that a click on an internal link in the preview moves the host.
   */
  public function testLinkClickNavigatesHost(): void {
    $other = $this->drupalCreateNode(['type' => 'article', 'title' => 'Other']);
    $this->drupalGet($this->node->toUrl());
    $this->enterFrame('hfe-preview');
    $this->assertSession()->waitForElement('css', 'h1');
    $href = $other->toUrl()->toString();
    $this->getSession()->executeScript("const a = document.createElement('a'); a.href = '$href'; a.textContent = 'Other page'; document.body.append(a);");
    $this->getSession()->getPage()->clickLink('Other page');
    $this->getSession()->switchToIFrame();
    $this->assertTrue($this->getSession()->wait(5000, "window.location.pathname === '$href'"));
  }

  /**
   * Tests that messages from other windows are ignored.
   */
  public function testHostileMessagesIgnored(): void {
    $this->drupalGet($this->node->toUrl());
    $this->assertSession()->waitForElement('css', 'iframe.hfe-preview');
    $url = $this->getSession()->getCurrentUrl();
    $target = 'node:' . $this->node->uuid() . ':title';
    $messages = json_encode([
      ['v' => 1, 'type' => 'hfe:navigate', 'path' => '//evil.example'],
      ['v' => 1, 'type' => 'hfe:navigate', 'path' => '/user'],
      ['v' => 1, 'type' => 'hfe:action', 'target' => 'node:x', 'lang' => 'en', 'action' => 'edit'],
      ['v' => 1, 'type' => 'hfe:action', 'target' => $target, 'lang' => 'en', 'action' => 'edit'],
      ['v' => 1, 'type' => 'hfe:saved'],
    ]);
    // A frame from another origin, and the host page itself (right origin,
    // wrong source).
    $this->getSession()->executeScript(<<<JS
      const frame = document.createElement('iframe');
      frame.src = 'data:text/html,' + encodeURIComponent('<script>' + $messages.map((m) => 'parent.postMessage(' + JSON.stringify(m) + ', "*");').join('') + '</' + 'script>');
      document.body.append(frame);
      window.hfeMarker = true;
      $messages.forEach((m) => window.postMessage(m, window.location.origin));
      JS);
    $this->getSession()->wait(1500);
    $this->assertSame($url, $this->getSession()->getCurrentUrl());
    $this->assertTrue($this->getSession()->evaluateScript('window.hfeMarker === true'));
    $this->assertTrue($this->assertSession()->elementExists('css', '.hfe-editor')->hasAttribute('hidden'));
  }

  /**
   * Tests that no overlay is drawn without edit mode or outside a frame.
   */
  public function testNoOverlaysWithoutEdit(): void {
    $this->drupalLogout();
    $this->grantPermissions(Role::load(RoleInterface::ANONYMOUS_ID), ['access content']);
    $path = '/node/' . $this->node->id();
    foreach ([['path' => $path], ['path' => $path, 'edit' => 1]] as $query) {
      $this->drupalGet('hfe-test/frontend', ['query' => $query]);
      $this->assertSession()->elementTextEquals('css', 'h1', 'Original');
      $this->getSession()->wait(500);
      $this->assertSession()->elementNotExists('css', '[data-hfe-overlay]');
    }
    $this->assertSession()->elementExists('css', 'script[src*="headless-frontend-editing/client.js"]');
  }

}
