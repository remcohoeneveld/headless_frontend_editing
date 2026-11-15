<?php

declare(strict_types=1);

namespace Drupal\Tests\headless_frontend_editing_paragraphs\Functional;

use Drupal\Core\Url;
use Drupal\headless_frontend_editing_paragraphs_test\Hook\LockedFieldHooks;
use Drupal\headless_frontend_editing_paragraphs_test\Hook\ParagraphAccessHooks;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\ParagraphInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\paragraphs\FunctionalJavascript\ParagraphsTestBaseTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests adding, reordering and deleting sections over HTTP.
 */
#[Group('headless_frontend_editing')]
#[RunTestsInSeparateProcesses]
final class ParagraphRoutesTest extends BrowserTestBase {

  use ParagraphsTestBaseTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'content_moderation',
    'headless_frontend_editing',
    'headless_frontend_editing_paragraphs',
    'headless_frontend_editing_paragraphs_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The page.
   */
  private NodeInterface $node;

  /**
   * The first section.
   */
  private ParagraphInterface $first;

  /**
   * The second section.
   */
  private ParagraphInterface $second;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->addParagraphedContentType('page', 'field_sections');
    $this->addParagraphsType('text');
    $this->addFieldtoParagraphType('text', 'field_text', 'string');
    $this->first = Paragraph::create(['type' => 'text', 'field_text' => 'First']);
    $this->second = Paragraph::create(['type' => 'text', 'field_text' => 'Second']);
    $this->node = $this->drupalCreateNode(['type' => 'page', 'field_sections' => [$this->first, $this->second]]);
  }

  /**
   * Logs in an editor, with or without access to edit pages.
   */
  private function login(bool $update = TRUE): void {
    $permissions = ['access content', 'use headless frontend editing'];
    if ($update) {
      $permissions[] = 'edit any page content';
    }
    $this->drupalLogin($this->drupalCreateUser($permissions));
  }

  /**
   * Returns the page reloaded from storage.
   */
  private function reload(): NodeInterface {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $storage->resetCache();
    $node = $storage->load($this->node->id());
    assert($node instanceof NodeInterface);
    return $node;
  }

  /**
   * Returns the section texts of the page, in order.
   *
   * @return string[]
   *   The texts.
   */
  private function texts(): array {
    \Drupal::entityTypeManager()->getStorage('paragraph')->resetCache();
    return array_map(fn ($paragraph) => $paragraph->get('field_text')->value, $this->reload()->get('field_sections')->referencedEntities());
  }

  /**
   * Returns the page layout URL of the page.
   */
  private function orderUrl(): Url {
    return Url::fromRoute('headless_frontend_editing_paragraphs.order', [
      'langcode' => 'en',
      'target' => 'node:' . $this->node->uuid(),
    ]);
  }

  /**
   * Returns the name of a section's weight field in the page layout.
   */
  private function weight(ParagraphInterface $paragraph): string {
    return 'fields[field_sections][sections][' . $paragraph->id() . '][weight]';
  }

  /**
   * Tests that the page layout lists the sections in order and reorders them.
   */
  public function testOrder(): void {
    $this->login();
    $revision = (int) $this->node->getRevisionId();
    $this->drupalGet($this->orderUrl());
    $this->assertSession()->elementTextEquals('css', 'title', 'Page layout | Drupal');
    $session = $this->assertSession();
    $rows = $this->getSession()->getPage()->findAll('css', 'table.hfe-order tr.hfe-order__row');
    $this->assertCount(2, $rows);
    $this->assertSame('text', $rows[0]->find('css', 'strong.hfe-order__type')->getText());
    $this->assertSame('First', $rows[0]->find('css', '.hfe-order__summary')->getText());
    $this->assertSame('Second', $rows[1]->find('css', '.hfe-order__summary')->getText());
    $target = 'paragraph:' . $this->first->uuid();
    $edit = Url::fromRoute('headless_frontend_editing.edit', ['langcode' => 'en', 'target' => $target])->toString();
    $session->elementExists('css', '.hfe-order__actions a[href="' . $edit . '"]');
    $delete = Url::fromRoute('headless_frontend_editing_paragraphs.delete', ['uuid' => $this->first->uuid()])->toString();
    $session->elementExists('css', '.hfe-order__actions a[href="' . $delete . '"]');
    $add = Url::fromRoute('headless_frontend_editing_paragraphs.add', [
      'uuid' => $this->second->uuid(),
      'position' => 'after',
    ])->toString();
    $session->elementExists('css', 'a.hfe-order__add[href="' . $add . '"]');
    // One section field: no caption.
    $session->elementNotExists('css', 'table.hfe-order caption');

    // Saving the same order adds no revision.
    $this->submitForm([], 'Save order');
    $session->addressEquals(Url::fromRoute('headless_frontend_editing.saved'));
    $this->assertSame($revision, (int) $this->reload()->getRevisionId());

    $this->drupalGet($this->orderUrl());
    $this->submitForm([$this->weight($this->first) => 1, $this->weight($this->second) => 0], 'Save order');
    $session->addressEquals(Url::fromRoute('headless_frontend_editing.saved'));
    $this->assertSame(['Second', 'First'], $this->texts());
    $node = $this->reload();
    $this->assertGreaterThan($revision, (int) $node->getRevisionId());
    $this->assertSame('Reordered sections from the frontend preview.', $node->getRevisionLogMessage());
  }

  /**
   * Tests that the page layout refuses to save over sections changed since.
   */
  public function testOrderStale(): void {
    $this->login();
    $this->drupalGet($this->orderUrl());
    $third = Paragraph::create(['type' => 'text', 'field_text' => 'Third']);
    $node = $this->reload();
    $node->get('field_sections')->appendItem($third);
    $node->save();
    $this->submitForm([$this->weight($this->first) => 1, $this->weight($this->second) => 0], 'Save order');
    $this->assertSession()->pageTextContains('The page changed since you opened this view. Reload and try again.');
    $this->assertSame(['First', 'Second', 'Third'], $this->texts());
  }

  /**
   * Tests the page layout tool in the editor panel.
   */
  public function testOrderTool(): void {
    $this->config('headless_frontend_editing.settings')->set('frontend_url', 'https://front.example')->save();
    $this->login();
    $this->drupalGet($this->node->toUrl());
    $this->assertSession()->elementExists('css', '.hfe-panel__tools button.hfe-tool:not(.hfe-tool--primary)[data-hfe-title="Page layout"][data-hfe-open="' . $this->orderUrl()->toString() . '"]');

    \Drupal::state()->set(LockedFieldHooks::STATE, 'field_sections');
    $this->drupalGet($this->node->toUrl());
    $this->assertSession()->elementExists('css', '.hfe-panel__tools button.hfe-tool--primary');
    $this->assertSession()->elementNotExists('css', '[data-hfe-title="Page layout"]');
  }

  /**
   * Tests that editing the whole page leaves out its sections.
   */
  public function testWholePageWithoutSections(): void {
    $this->login();
    $target = 'node:' . $this->node->uuid();
    $this->drupalGet(Url::fromRoute('headless_frontend_editing.edit', ['langcode' => 'en', 'target' => $target]));
    $this->assertSession()->fieldExists('title[0][value]');
    $this->assertSession()->elementNotExists('css', '[name^="field_sections"]');
    $this->submitForm(['title[0][value]' => 'Renamed'], 'Save');
    $this->assertSame('Renamed', $this->reload()->getTitle());
    $this->assertSame(['First', 'Second'], $this->texts());

    // A section is still edited with its own fields.
    $target = 'paragraph:' . $this->first->uuid();
    $this->drupalGet(Url::fromRoute('headless_frontend_editing.edit', ['langcode' => 'en', 'target' => $target]));
    $this->assertSession()->fieldExists('field_text[0][value]');
    $this->assertSession()->elementTextEquals('css', 'title', 'Edit text | Drupal');
  }

  /**
   * Tests deleting a section after confirming.
   */
  public function testDelete(): void {
    $this->login();
    $revision = (int) $this->node->getRevisionId();
    $this->drupalGet(Url::fromRoute('headless_frontend_editing_paragraphs.delete', ['uuid' => $this->first->uuid()]));
    $this->assertSession()->pageTextContains('Delete the text section?');
    $this->submitForm([], 'Delete');
    $this->assertSession()->addressEquals(Url::fromRoute('headless_frontend_editing.saved'));
    $this->assertSame(['Second'], $this->texts());
    $node = $this->reload();
    $this->assertGreaterThan($revision, (int) $node->getRevisionId());
    $this->assertSame('Deleted text from the frontend preview.', $node->getRevisionLogMessage());

    // The only section left cannot be deleted.
    $this->drupalGet(Url::fromRoute('headless_frontend_editing_paragraphs.delete', ['uuid' => $this->second->uuid()]));
    $this->assertSession()->buttonNotExists('Delete');
  }

  /**
   * Tests that deleting needs delete access to the section.
   */
  public function testDeleteNeedsDeleteAccess(): void {
    \Drupal::state()->set(ParagraphAccessHooks::NO_DELETE, TRUE);
    $this->login();
    $this->drupalGet(Url::fromRoute('headless_frontend_editing_paragraphs.delete', ['uuid' => $this->first->uuid()]));
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests adding a section after another one, and editing it.
   */
  public function testAddAndEdit(): void {
    $this->login();
    $uuid = $this->first->uuid();
    $this->drupalGet(Url::fromRoute('headless_frontend_editing_paragraphs.add', ['uuid' => $uuid, 'position' => 'after']));
    $this->clickLink('text');
    $this->assertSession()->addressEquals(Url::fromRoute('headless_frontend_editing_paragraphs.add_form', [
      'uuid' => $this->first->uuid(),
      'position' => 'after',
      'bundle' => 'text',
    ]));
    $this->submitForm(['field_text[0][value]' => 'Between'], 'Save');
    $this->assertSession()->addressEquals(Url::fromRoute('headless_frontend_editing.saved'));
    $this->assertSame(['First', 'Between', 'Second'], $this->texts());
    $this->assertSame('Added text from the frontend preview.', $this->reload()->getRevisionLogMessage());

    $target = 'paragraph:' . $this->second->uuid();
    $this->drupalGet(Url::fromRoute('headless_frontend_editing.edit', ['langcode' => 'en', 'target' => $target]));
    $this->submitForm(['field_text[0][value]' => 'Changed'], 'Save');
    $this->assertSame(['First', 'Between', 'Changed'], $this->texts());
    $this->assertSame('Updated text from the frontend preview.', $this->reload()->getRevisionLogMessage());

    $this->drupalGet(Url::fromRoute('headless_frontend_editing_paragraphs.add_form', [
      'uuid' => $this->first->uuid(),
      'position' => 'after',
      'bundle' => 'missing',
    ]));
    $this->assertSession()->statusCodeEquals(404);
  }

  /**
   * Tests that every route needs update access to the page.
   */
  public function testNoAccess(): void {
    $this->login(FALSE);
    $uuid = $this->first->uuid();
    foreach ([
      Url::fromRoute('headless_frontend_editing_paragraphs.add', ['uuid' => $uuid, 'position' => 'before']),
      Url::fromRoute('headless_frontend_editing_paragraphs.add_form', [
        'uuid' => $uuid,
        'position' => 'before',
        'bundle' => 'text',
      ]),
      Url::fromRoute('headless_frontend_editing_paragraphs.delete', ['uuid' => $uuid]),
      $this->orderUrl(),
    ] as $url) {
      $this->drupalGet($url);
      $this->assertSession()->statusCodeEquals(403);
    }
    $this->assertSame(['First', 'Second'], $this->texts());
  }

  /**
   * Tests that every route needs edit access to the sections field.
   */
  public function testLockedField(): void {
    \Drupal::state()->set(LockedFieldHooks::STATE, 'field_sections');
    $this->login();
    $uuid = $this->first->uuid();
    foreach ([
      Url::fromRoute('headless_frontend_editing.edit', ['langcode' => 'en', 'target' => "paragraph:$uuid"]),
      Url::fromRoute('headless_frontend_editing.edit', ['langcode' => 'en', 'target' => "paragraph:$uuid:field_text"]),
      Url::fromRoute('headless_frontend_editing_paragraphs.add', ['uuid' => $uuid, 'position' => 'before']),
      Url::fromRoute('headless_frontend_editing_paragraphs.delete', ['uuid' => $uuid]),
      $this->orderUrl(),
    ] as $url) {
      $this->drupalGet($url);
      $this->assertSession()->statusCodeEquals(403);
    }
    $this->assertSame(['First', 'Second'], $this->texts());
  }

  /**
   * Tests that a page with a pending draft refuses every section route.
   */
  public function testPendingDraft(): void {
    $this->createEditorialWorkflow('page');
    $node = $this->reload();
    $node->set('moderation_state', 'published')->save();
    $node->set('moderation_state', 'draft')->save();
    $this->drupalLogin($this->drupalCreateUser([
      'access content',
      'use headless frontend editing',
      'edit any page content',
      "use {$this->workflow->id()} transition create_new_draft",
      "use {$this->workflow->id()} transition publish",
    ]));
    $uuid = $this->first->uuid();
    foreach ([
      Url::fromRoute('headless_frontend_editing.edit', ['langcode' => 'en', 'target' => "paragraph:$uuid"]),
      Url::fromRoute('headless_frontend_editing_paragraphs.add', ['uuid' => $uuid, 'position' => 'before']),
      Url::fromRoute('headless_frontend_editing_paragraphs.add_form', [
        'uuid' => $uuid,
        'position' => 'before',
        'bundle' => 'text',
      ]),
      Url::fromRoute('headless_frontend_editing_paragraphs.delete', ['uuid' => $uuid]),
      $this->orderUrl(),
    ] as $url) {
      $this->drupalGet($url);
      $this->assertSession()->statusCodeEquals(403);
    }
  }

}
