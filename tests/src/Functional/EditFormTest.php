<?php

declare(strict_types=1);

namespace Drupal\Tests\headless_frontend_editing\Functional;

use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\NodeInterface;
use Drupal\node\NodeStorageInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\content_moderation\Traits\ContentModerationTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests editing entities and fields in the sidebar form.
 */
#[Group('headless_frontend_editing')]
#[RunTestsInSeparateProcesses]
final class EditFormTest extends BrowserTestBase {

  use ContentModerationTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'field',
    'text',
    'language',
    'content_translation',
    'workflows',
    'content_moderation',
    'headless_frontend_editing',
    'headless_frontend_editing_test_access',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The article edited.
   */
  private NodeInterface $node;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalCreateContentType(['type' => 'article']);
    // Created in the past, so a later save elsewhere has a newer changed time.
    $this->node = $this->drupalCreateNode([
      'type' => 'article',
      'title' => 'Original',
      'body' => 'Body text',
      'changed' => \Drupal::time()->getRequestTime() - 100,
    ]);
  }

  /**
   * Logs in a user with the given permissions plus the module permission.
   *
   * @param string[] $permissions
   *   Extra permissions.
   */
  private function loginEditor(array $permissions = ['edit any article content']): void {
    $this->drupalLogin($this->drupalCreateUser([...$permissions, 'use headless frontend editing', 'access content']));
  }

  /**
   * Returns the node storage, with its static cache cleared.
   */
  private function storage(): NodeStorageInterface {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $storage->resetCache();
    return $storage;
  }

  /**
   * Counts the revisions of a node.
   */
  private function revisionCount(NodeInterface $node): int {
    return (int) $this->storage()->getQuery()->allRevisions()->accessCheck(FALSE)->condition('nid', $node->id())->count()->execute();
  }

  /**
   * Saving a field target creates a logged revision and goes to saved.
   */
  public function testSaveCreatesRevision(): void {
    $this->loginEditor();
    $count = $this->revisionCount($this->node);
    $this->drupalGet('headless-frontend-editing/edit/en/node:' . $this->node->uuid() . ':title');
    $this->assertSession()->fieldNotExists('body[0][value]');
    $this->assertSession()->elementExists('css', '.hfe-sidebar-page');
    $this->submitForm(['title[0][value]' => 'Changed'], 'Save');
    $node = $this->storage()->load($this->node->id());
    $this->assertSame('Changed', $node->getTitle());
    $this->assertSame($count + 1, $this->revisionCount($node));
    $this->assertStringContainsString('from the frontend preview', $node->getRevisionLogMessage());
    $this->assertSession()->addressEquals('headless-frontend-editing/saved');
    $this->assertSession()->pageTextContains('Saved. Updating the preview');
    $this->assertSession()->elementExists('css', '.hfe-sidebar-page .hfe-saved');
    $this->assertSession()->responseContains('js/sidebar.js');
  }

  /**
   * An entity target gets the whole form, without the node metadata.
   */
  public function testWholeNode(): void {
    $this->loginEditor();
    $this->drupalGet('headless-frontend-editing/edit/en/node:' . $this->node->uuid());
    $this->assertSession()->fieldExists('title[0][value]');
    $this->assertSession()->fieldExists('body[0][value]');
    $this->assertSession()->fieldNotExists('revision_log[0][value]');
    $this->assertSession()->elementExists('css', 'form.hfe-form [name="hfe_live"]');
  }

  /**
   * Hidden metadata keeps its values, also inside grouped widget elements.
   */
  public function testHiddenMetadataKept(): void {
    $this->node->setPromoted(TRUE)->setSticky(TRUE)->save();
    $this->loginEditor(['edit any article content', 'administer nodes']);
    foreach (['', ':title'] as $field) {
      \Drupal::state()->delete('hfe_test_flag');
      $this->drupalGet('headless-frontend-editing/edit/en/node:' . $this->node->uuid() . $field);
      $this->assertSession()->fieldNotExists('sticky[value]');
      $this->assertSession()->fieldNotExists('title[0][hfe_test_flag]');
      $this->submitForm(['title[0][value]' => "Saved$field"], 'Save');
      $node = $this->storage()->load($this->node->id());
      $this->assertSame("Saved$field", $node->getTitle());
      $this->assertTrue($node->isPromoted());
      $this->assertTrue($node->isSticky());
      \Drupal::state()->resetCache();
      $this->assertSame(1, \Drupal::state()->get('hfe_test_flag'));
    }
  }

  /**
   * Only the translation in the target's language changes.
   */
  public function testTranslation(): void {
    ConfigurableLanguage::createFromLangcode('nl')->save();
    \Drupal::service('content_translation.manager')->setEnabled('node', 'article', TRUE);
    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
    $node = $this->storage()->load($this->node->id());
    $node->addTranslation('nl', ['title' => 'Translated'] + $node->toArray())->save();
    $this->loginEditor();

    $this->drupalGet('headless-frontend-editing/edit/nl/node:' . $node->uuid() . ':title');
    $this->submitForm(['title[0][value]' => 'Translation changed'], 'Save');
    $node = $this->storage()->load($node->id());
    $this->assertSame('Translation changed', $node->getTranslation('nl')->getTitle());
    $this->assertSame('Original', $node->getTitle());

    $this->drupalGet('headless-frontend-editing/edit/en/node:' . $node->uuid() . ':title');
    $this->submitForm(['title[0][value]' => 'Changed'], 'Save');
    $node = $this->storage()->load($node->id());
    $this->assertSame('Changed', $node->getTitle());
    $this->assertSame('Translation changed', $node->getTranslation('nl')->getTitle());
  }

  /**
   * A focused form cannot publish past the editor's workflow transitions.
   */
  public function testModerationBypass(): void {
    $this->drupalCreateContentType(['type' => 'page']);
    $workflow = $this->createEditorialWorkflow();
    $this->addEntityTypeAndBundleToWorkflow($workflow, 'node', 'page');
    $page = $this->drupalCreateNode(['type' => 'page', 'title' => 'Live', 'moderation_state' => 'published']);
    $this->loginEditor([
      'edit any page content',
      'use editorial transition create_new_draft',
      'view latest version',
      'view any unpublished content',
    ]);

    $this->drupalGet('headless-frontend-editing/edit/en/node:' . $page->uuid() . ':title');
    $this->submitForm(['title[0][value]' => 'Draft title'], 'Save');

    $storage = $this->storage();
    $this->assertSame('Live', $storage->load($page->id())->getTitle());
    $latest = $storage->loadRevision($storage->getLatestRevisionId($page->id()));
    assert($latest instanceof NodeInterface);
    $this->assertSame('Draft title', $latest->getTitle());
    $this->assertSame('draft', $latest->get('moderation_state')->value);

    // Editing the published revision now would lose the draft.
    $this->drupalGet('headless-frontend-editing/edit/en/node:' . $page->uuid() . ':title');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet('headless-frontend-editing/edit/en/node:' . $page->uuid());
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * A form for an entity saved elsewhere in the meantime is refused.
   */
  public function testStaleForm(): void {
    $this->loginEditor();
    $count = $this->revisionCount($this->node);
    $this->drupalGet('headless-frontend-editing/edit/en/node:' . $this->node->uuid() . ':title');
    $node = $this->storage()->load($this->node->id());
    $node->setTitle('Elsewhere')->save();
    $this->submitForm(['title[0][value]' => 'Stale'], 'Save');
    $this->assertSession()->pageTextContains('The content has either been modified by another user');
    $this->assertSame($count, $this->revisionCount($node));
    $this->assertSame('Elsewhere', $this->storage()->load($node->id())->getTitle());
  }

  /**
   * Entity and field access are enforced; unknown targets are not found.
   */
  public function testAccess(): void {
    $uuid = $this->node->uuid();
    $this->loginEditor(['access content']);
    $this->drupalGet("headless-frontend-editing/edit/en/node:$uuid:title");
    $this->assertSession()->statusCodeEquals(403);

    $this->loginEditor();
    $this->drupalGet("headless-frontend-editing/edit/en/node:$uuid:promote");
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet('headless-frontend-editing/edit/en/node:00000000-0000-4000-8000-000000000000');
    $this->assertSession()->statusCodeEquals(404);
    $this->drupalGet("headless-frontend-editing/edit/en/node:$uuid");
    $this->assertSession()->statusCodeEquals(200);
  }

}
