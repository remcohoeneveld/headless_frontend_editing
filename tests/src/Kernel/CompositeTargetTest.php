<?php

declare(strict_types=1);

namespace Drupal\Tests\headless_frontend_editing\Kernel;

use Drupal\headless_frontend_editing\Controller\EditController;
use Drupal\headless_frontend_editing\Controller\ProtocolController;
use Drupal\headless_frontend_editing\Hook\EntityFormHooks;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\Tests\paragraphs\FunctionalJavascript\ParagraphsTestBaseTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests that composite entities are not editable without a module for them.
 *
 * @group headless_frontend_editing
 * @runTestsInSeparateProcesses
 */
final class CompositeTargetTest extends KernelTestBase {

  use ParagraphsTestBaseTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'file', 'text', 'node', 'entity_reference_revisions', 'paragraphs',
    'headless_frontend_editing',
  ];

  /**
   * Tests that a paragraph is not described, editable or given the form.
   */
  public function testParagraphRefused(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('paragraph');
    $this->installSchema('node', ['node_access']);
    $this->addParagraphedContentType('page', 'field_sections');
    $this->addParagraphsType('text');
    $user = $this->setUpCurrentUser([], ['access content', 'edit any page content']);
    $paragraph = Paragraph::create(['type' => 'text']);
    $node = Node::create(['type' => 'page', 'title' => 'Page', 'field_sections' => [$paragraph]]);
    $node->save();

    $targets = [
      ['id' => 'paragraph:' . $paragraph->uuid(), 'lang' => 'en'],
      ['id' => 'node:' . $node->uuid(), 'lang' => 'en'],
    ];
    $response = ProtocolController::create($this->container)->describe(new Request(content: json_encode(['targets' => $targets])));
    $this->assertSame(['node:' . $node->uuid()], array_column(json_decode($response->getContent(), TRUE)['targets'], 'id'));

    $controller = EditController::create($this->container);
    $this->assertTrue($controller->access($user, 'en', 'paragraph:' . $paragraph->uuid())->isForbidden());
    $this->assertNull($this->container->get('entity_type.manager')->getDefinition('paragraph')->getFormClass(EntityFormHooks::OPERATION));
  }

}
