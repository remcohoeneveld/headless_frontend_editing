<?php

declare(strict_types=1);

namespace Drupal\Tests\headless_frontend_editing\Kernel;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\headless_frontend_editing\FieldFocus;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\content_moderation\Traits\ContentModerationTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that field targets get a form with only that field.
 */
#[Group('headless_frontend_editing')]
#[RunTestsInSeparateProcesses]
final class FieldFocusTest extends KernelTestBase {

  use ContentModerationTestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'filter', 'text', 'node', 'workflows', 'content_moderation', 'headless_frontend_editing',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('content_moderation_state');
    $this->installConfig(['system', 'node', 'filter', 'content_moderation', 'headless_frontend_editing']);
    $this->installSchema('node', ['node_access']);
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    foreach (['body', 'field_intro'] as $field_name) {
      FieldStorageConfig::create(['field_name' => $field_name, 'entity_type' => 'node', 'type' => 'string'])->save();
      FieldConfig::create(['field_name' => $field_name, 'entity_type' => 'node', 'bundle' => 'article'])->save();
    }
    $this->container->get('entity_display.repository')->getFormDisplay('node', 'article')
      ->setComponent('body')
      ->setComponent('field_intro')
      ->save();
    $this->setUpCurrentUser([], ['access content', 'edit any article content']);
  }

  /**
   * Only the focused field gets a widget.
   */
  public function testOnlyFocusedField(): void {
    $node = Node::create(['type' => 'article', 'title' => 'Hello']);
    $node->save();
    $this->container->get(FieldFocus::class)->set('field_intro');
    $form = $this->container->get('entity.form_builder')->getForm($node, 'headless_editing', ['hfe' => TRUE]);
    $this->assertArrayHasKey('widget', $form['field_intro']);
    $this->assertArrayNotHasKey('body', $form);
    $this->assertArrayNotHasKey('title', $form);
  }

  /**
   * The moderation state stays in a focused form display.
   */
  public function testModerationStateKept(): void {
    $workflow = $this->createEditorialWorkflow();
    $this->addEntityTypeAndBundleToWorkflow($workflow, 'node', 'article');
    $node = Node::create(['type' => 'article', 'title' => 'Hello']);
    $node->save();
    $this->container->get(FieldFocus::class)->set('field_intro');
    $components = EntityFormDisplay::collectRenderDisplay($node, 'headless_editing')->getComponents();
    $this->assertArrayHasKey('field_intro', $components);
    $this->assertArrayHasKey('moderation_state', $components);
    $this->assertArrayNotHasKey('body', $components);
  }

}
