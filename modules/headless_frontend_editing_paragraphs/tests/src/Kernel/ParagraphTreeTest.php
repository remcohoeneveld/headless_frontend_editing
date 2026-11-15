<?php

declare(strict_types=1);

namespace Drupal\Tests\headless_frontend_editing_paragraphs\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\headless_frontend_editing\LivePreview;
use Drupal\headless_frontend_editing_paragraphs\Form\ParagraphForm;
use Drupal\headless_frontend_editing_paragraphs\ParagraphTree;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\ParagraphInterface;
use Drupal\Tests\paragraphs\FunctionalJavascript\ParagraphsTestBaseTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Tests finding, placing and saving paragraphs in their root entity.
 */
#[Group('headless_frontend_editing')]
#[RunTestsInSeparateProcesses]
final class ParagraphTreeTest extends KernelTestBase {

  use ParagraphsTestBaseTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'file', 'text', 'node', 'entity_reference_revisions', 'paragraphs',
    'headless_frontend_editing', 'headless_frontend_editing_paragraphs',
  ];

  /**
   * The paragraph tree service.
   */
  private ParagraphTree $tree;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('paragraph');
    $this->installSchema('node', ['node_access']);
    $this->addParagraphedContentType('page', 'field_sections');
    $this->addParagraphsType('text');
    $this->addParagraphsType('group');
    $this->addParagraphsField('group', 'field_children', 'paragraph');
    $this->tree = $this->container->get(ParagraphTree::class);
  }

  /**
   * Creates a page with text sections a and b.
   *
   * @return array{\Drupal\node\NodeInterface, \Drupal\paragraphs\ParagraphInterface, \Drupal\paragraphs\ParagraphInterface}
   *   The node and its two sections.
   */
  private function page(): array {
    $a = Paragraph::create(['type' => 'text']);
    $b = Paragraph::create(['type' => 'text']);
    $node = Node::create(['type' => 'page', 'title' => 'Page', 'field_sections' => [$a, $b]]);
    $node->save();
    return [$node, $a, $b];
  }

  /**
   * Returns the target ids of the node's sections, reloaded.
   *
   * @return int[]
   *   The paragraph ids in order.
   */
  private function order(NodeInterface $node): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache();
    return array_map('intval', array_column($storage->load($node->id())->get('field_sections')->getValue(), 'target_id'));
  }

  /**
   * Tests that a nested paragraph's root is the node.
   */
  public function testRootNested(): void {
    $child = Paragraph::create(['type' => 'text']);
    $group = Paragraph::create(['type' => 'group', 'field_children' => [$child]]);
    $node = Node::create(['type' => 'page', 'title' => 'Page', 'field_sections' => [$group]]);
    $node->save();
    $child = Paragraph::load($child->id());
    assert($child instanceof ParagraphInterface);
    $this->assertSame($node->id(), $this->tree->root($child)?->id());
    $this->assertSame('node', $this->tree->root($child)->getEntityTypeId());
  }

  /**
   * Tests inserting a new paragraph before and after an anchor.
   */
  public function testInsertBeforeAfter(): void {
    $this->setUpCurrentUser([], ['access content', 'edit any page content']);
    foreach (['before' => [2, 0, 1], 'after' => [0, 2, 1]] as $position => $expected) {
      [$node, $a, $b] = $this->page();
      $new = Paragraph::create(['type' => 'text']);
      $root = $this->tree->attach($new, [Paragraph::load($a->id()), $position]);
      $this->tree->saveRoot($root, 'Added');
      $ids = [(int) $a->id(), (int) $b->id(), (int) $new->id()];
      $this->assertSame(array_map(fn ($i) => $ids[$i], $expected), $this->order($node), $position);
    }
  }

  /**
   * Tests that moving the first item up is not possible.
   */
  public function testMoveBounds(): void {
    [$node, $a, $b] = $this->page();
    $this->setUpCurrentUser([], ['access content', 'edit any page content']);
    $this->assertFalse($this->tree->move(Paragraph::load($a->id()), 'up'));
    $this->assertSame([(int) $a->id(), (int) $b->id()], $this->order($node));
    $this->assertTrue($this->tree->move(Paragraph::load($a->id()), 'down'));
    $this->assertSame([(int) $b->id(), (int) $a->id()], $this->order($node));
  }

  /**
   * Tests that saving the root adds a logged revision.
   */
  public function testSaveRootRevision(): void {
    [$node] = $this->page();
    $user = $this->setUpCurrentUser([], ['access content', 'edit any page content']);
    $revision = (int) $node->getRevisionId();
    $this->tree->saveRoot($node, 'Reordered sections from the frontend preview.');
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache();
    $node = $storage->load($node->id());
    assert($node instanceof NodeInterface);
    $count = $storage->getQuery()->allRevisions()->accessCheck(FALSE)->condition('nid', $node->id())->count()->execute();
    $this->assertSame(2, (int) $count);
    $this->assertGreaterThan($revision, (int) $node->getRevisionId());
    $this->assertSame('Reordered sections from the frontend preview.', $node->getRevisionLogMessage());
    $this->assertSame($user->id(), $node->getRevisionUserId());
  }

  /**
   * Tests that saving the root refuses a copy older than its latest revision.
   *
   * A form submitted from the form cache carries the page as it was then.
   */
  public function testSaveRootRefusesStale(): void {
    [$node] = $this->page();
    $this->setUpCurrentUser([], ['access content', 'edit any page content']);
    $stale = Node::load($node->id());
    $this->tree->saveRoot($this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($node->id()), 'First.');
    $this->expectException(AccessDeniedHttpException::class);
    $this->tree->saveRoot($stale, 'Second, from the old copy.');
  }

  /**
   * Tests that saving the root needs update access.
   */
  public function testSaveRootNeedsAccess(): void {
    [$node] = $this->page();
    $this->setUpCurrentUser([], ['access content']);
    $this->expectException(AccessDeniedHttpException::class);
    $this->tree->saveRoot($node, 'Not allowed.');
  }

  /**
   * Tests that the live preview shows a new section in place, unsaved.
   */
  public function testLivePreviewNewSection(): void {
    $this->installConfig(['system', 'node', 'headless_frontend_editing']);
    $this->setUpCurrentUser([], ['access content', 'edit any page content']);
    [$node, $a, $b] = $this->page();
    $new = Paragraph::create(['type' => 'text']);
    $form_object = $this->container->get('entity_type.manager')->getFormObject('paragraph', 'headless_editing')->setEntity($new);
    $this->assertInstanceOf(ParagraphForm::class, $form_object);
    $form_state = (new FormState())->set('hfe', TRUE)->set('hfe_insert', [Paragraph::load($a->id()), 'after']);
    $form = $this->container->get('form_builder')->buildForm($form_object, $form_state);

    $this->container->get(LivePreview::class)->callback($form, $form_state);

    $preview = $this->container->get('tempstore.private')->get('node_preview')->get('hfe-live-' . $new->uuid());
    $sections = $preview->getFormObject()->getEntity()->get('field_sections');
    $this->assertCount(3, $sections);
    $this->assertSame($new->uuid(), $sections->get(1)->entity->uuid());
    $this->assertSame([(int) $a->id(), (int) $b->id()], $this->order($node));
  }

  /**
   * Tests that a paragraph its parent no longer references is not placed.
   */
  public function testUnreferenced(): void {
    $this->installConfig(['system', 'node', 'headless_frontend_editing']);
    $this->setUpCurrentUser([], ['access content', 'edit any page content']);
    [$node, $a, $b] = $this->page();
    $orphan = Paragraph::create(['type' => 'text']);
    $orphan->setParentEntity($node, 'field_sections')->save();

    $this->assertNull($this->tree->load($orphan->uuid()));
    $this->assertNull($this->tree->attach($orphan));
    $this->assertNull($this->tree->attach(Paragraph::create(['type' => 'text']), [$orphan, 'after']));

    // The sidebar form saves it on its own instead of failing.
    $form_object = $this->container->get('entity_type.manager')->getFormObject('paragraph', 'headless_editing')->setEntity($orphan);
    $this->assertSame(SAVED_UPDATED, $form_object->save([], new FormState()));
    $this->assertSame([(int) $a->id(), (int) $b->id()], $this->order($node));
  }

}
