<?php

declare(strict_types=1);

namespace Drupal\Tests\headless_frontend_editing_paragraphs\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\headless_frontend_editing\Controller\ProtocolController;
use Drupal\headless_frontend_editing_paragraphs\ParagraphTree;
use Drupal\headless_frontend_editing_paragraphs_test\Hook\LockedFieldHooks;
use Drupal\headless_frontend_editing_paragraphs_test\Hook\ParagraphAccessHooks;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\paragraphs\ParagraphInterface;
use Drupal\Tests\paragraphs\FunctionalJavascript\ParagraphsTestBaseTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the section actions describe offers and the types that can be added.
 */
#[Group('headless_frontend_editing')]
#[RunTestsInSeparateProcesses]
final class ParagraphActionsTest extends KernelTestBase {

  use ParagraphsTestBaseTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'file', 'text', 'node', 'language', 'content_translation',
    'entity_reference_revisions', 'paragraphs', 'headless_frontend_editing', 'headless_frontend_editing_paragraphs',
    'headless_frontend_editing_paragraphs_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('paragraph');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['language']);
    $this->addParagraphedContentType('page', 'field_sections');
    $this->addParagraphsType('text');
    $this->addParagraphsType('hero');
    $this->setUpCurrentUser([], ['access content', 'edit any page content']);
  }

  /**
   * Describes the given paragraphs in a language.
   *
   * @param \Drupal\paragraphs\ParagraphInterface[] $paragraphs
   *   The paragraphs.
   * @param string $lang
   *   The language.
   *
   * @return array<string, array<string, bool>>
   *   Per described target id, the disabled flag of each action by action id.
   */
  private function actions(array $paragraphs, string $lang = 'en'): array {
    $targets = array_map(fn ($p) => ['id' => 'paragraph:' . $p->uuid(), 'lang' => $lang], $paragraphs);
    $response = ProtocolController::create($this->container)->describe(new Request(content: json_encode(['targets' => $targets])));
    $result = [];
    foreach (json_decode($response->getContent(), TRUE)['targets'] as $target) {
      $result[$target['id']] = array_column($target['actions'], 'disabled', 'id');
    }
    return $result;
  }

  /**
   * Creates a page with the given number of text sections.
   *
   * @return \Drupal\paragraphs\ParagraphInterface[]
   *   The sections.
   */
  private function sections(int $count, string $langcode = 'en'): array {
    $sections = [];
    for ($i = 0; $i < $count; $i++) {
      $sections[] = Paragraph::create(['type' => 'text', 'langcode' => $langcode]);
    }
    Node::create(['type' => 'page', 'title' => 'Page', 'langcode' => $langcode, 'field_sections' => $sections])->save();
    return $sections;
  }

  /**
   * Tests the disabled flags at the first, middle and last position.
   */
  public function testPositions(): void {
    [$first, $middle, $last] = $this->sections(3);
    $actions = $this->actions([$first, $middle, $last]);
    $all = ['edit', 'up', 'down', 'delete', 'add-before', 'add-after'];
    $this->assertSame($all, array_keys($actions['paragraph:' . $first->uuid()]));
    $this->assertTrue($actions['paragraph:' . $first->uuid()]['up']);
    $this->assertFalse($actions['paragraph:' . $first->uuid()]['down']);
    $this->assertFalse($actions['paragraph:' . $middle->uuid()]['up']);
    $this->assertFalse($actions['paragraph:' . $middle->uuid()]['down']);
    $this->assertTrue($actions['paragraph:' . $last->uuid()]['down']);
    $this->assertFalse($actions['paragraph:' . $last->uuid()]['delete']);
  }

  /**
   * Tests that a section is labelled with its type, as plain text.
   */
  public function testLabel(): void {
    ParagraphsType::load('text')->set('label', 'Text & media')->save();
    [$section] = $this->sections(2);
    $targets = [['id' => 'paragraph:' . $section->uuid(), 'lang' => 'en']];
    $response = ProtocolController::create($this->container)->describe(new Request(content: json_encode(['targets' => $targets])));
    $target = json_decode($response->getContent(), TRUE)['targets'][0];
    $this->assertSame('Text & media', $target['label']);
    $this->assertSame('Edit Text & media', $target['actions'][0]['label']);
  }

  /**
   * Tests that the only section cannot be deleted or moved.
   */
  public function testOnlySection(): void {
    [$only] = $this->sections(1);
    $actions = $this->actions([$only])['paragraph:' . $only->uuid()];
    $this->assertTrue($actions['delete']);
    $this->assertTrue($actions['up']);
    $this->assertTrue($actions['down']);
    $this->assertFalse($actions['add-after']);
  }

  /**
   * Tests that negate makes target_bundles the excluded types.
   */
  public function testNegate(): void {
    $field = FieldConfig::loadByName('node', 'page', 'field_sections');
    assert($field instanceof FieldConfig);
    $field->setSetting('handler_settings', ['negate' => 1, 'target_bundles' => ['hero' => 'hero']])->save();
    [$anchor] = $this->sections(1);
    $anchor = Paragraph::load($anchor->id());
    assert($anchor instanceof ParagraphInterface);
    $types = array_keys($this->container->get(ParagraphTree::class)->allowedTypes($anchor));
    $this->assertSame(['text'], $types);

    $field->setSetting('handler_settings', ['negate' => 0, 'target_bundles' => ['hero' => 'hero']])->save();
    $this->container->get('entity_type.manager')->getStorage('node')->resetCache();
    $anchor = Paragraph::load($anchor->id());
    assert($anchor instanceof ParagraphInterface);
    $this->assertSame(['hero'], array_keys($this->container->get(ParagraphTree::class)->allowedTypes($anchor)));
  }

  /**
   * Tests that types the user may not create are not offered.
   */
  public function testCreateAccess(): void {
    $this->container->get('state')->set(ParagraphAccessHooks::NO_CREATE, 'hero');
    [$anchor] = $this->sections(1);
    $anchor = Paragraph::load($anchor->id());
    assert($anchor instanceof ParagraphInterface);
    $this->assertSame(['text'], array_keys($this->container->get(ParagraphTree::class)->allowedTypes($anchor)));
  }

  /**
   * Tests that delete is disabled without delete access to the section.
   */
  public function testDeleteAccess(): void {
    [$first, $second] = $this->sections(2);
    $this->assertFalse($this->actions([$first])['paragraph:' . $first->uuid()]['delete']);
    $this->container->get('state')->set(ParagraphAccessHooks::NO_DELETE, TRUE);
    $this->container->get('entity_type.manager')->getAccessControlHandler('paragraph')->resetCache();
    $this->assertTrue($this->actions([$second])['paragraph:' . $second->uuid()]['delete']);
  }

  /**
   * Tests that a translation of an untranslatable section field only edits.
   */
  public function testTranslationOnlyEdit(): void {
    ConfigurableLanguage::createFromLangcode('nl')->save();
    $manager = $this->container->get('content_translation.manager');
    $manager->setEnabled('node', 'page', TRUE);
    $manager->setEnabled('paragraph', 'text', TRUE);
    $field = FieldConfig::loadByName('node', 'page', 'field_sections');
    assert($field instanceof FieldConfig);
    $field->setTranslatable(FALSE)->save();
    $this->container->get('entity_type.bundle.info')->clearCachedBundles();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();

    [$first, $second] = $this->sections(2, 'nl');
    $node = Paragraph::load($first->id())->getParentEntity();
    $node->addTranslation('en', ['title' => 'Page EN'])->save();
    foreach ([$first, $second] as $paragraph) {
      Paragraph::load($paragraph->id())->addTranslation('en')->save();
    }

    $this->assertSame(['edit'], array_keys($this->actions([$first], 'en')['paragraph:' . $first->uuid()]));
    $this->assertContains('delete', array_keys($this->actions([$first], 'nl')['paragraph:' . $first->uuid()]));
  }

  /**
   * Tests that the section actions load with the editing host.
   */
  public function testActionsLoadWithHost(): void {
    $library = $this->container->get('library.discovery')->getLibraryByName('headless_frontend_editing', 'host');
    $files = array_column($library['js'], 'data');
    $this->assertStringEndsWith('/js/host.js', $files[0]);
    $this->assertStringEndsWith('headless_frontend_editing_paragraphs/js/actions.js', end($files));
  }

  /**
   * Tests that a section whose parent field is locked is not offered at all.
   */
  public function testLockedField(): void {
    $this->addParagraphsType('group');
    $this->addParagraphsField('group', 'field_children', 'paragraph');
    $child = Paragraph::create(['type' => 'text']);
    $sibling = Paragraph::create(['type' => 'text']);
    $group = Paragraph::create(['type' => 'group', 'field_children' => [$child, $sibling]]);
    Node::create(['type' => 'page', 'title' => 'Page', 'field_sections' => [$group]])->save();
    $this->assertContains('up', array_keys($this->actions([$child])['paragraph:' . $child->uuid()]));

    // A locked ancestor field counts too, also for editing the section.
    $this->container->get('state')->set(LockedFieldHooks::STATE, 'field_sections');
    $this->assertSame([], $this->actions([$child]));
    $this->assertSame([], $this->actions([$group]));
  }

  /**
   * Tests that a section no page references is not offered.
   */
  public function testOrphan(): void {
    $orphan = Paragraph::create(['type' => 'text']);
    $orphan->save();
    $this->assertSame([], $this->actions([$orphan]));
  }

}
