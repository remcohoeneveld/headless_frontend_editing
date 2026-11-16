<?php

declare(strict_types=1);

namespace Drupal\Tests\headless_frontend_editing\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\headless_frontend_editing\Hook\EntityFormHooks;
use Drupal\headless_frontend_editing\LivePreview;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * Tests the live preview callback.
 *
 * @group headless_frontend_editing
 * @runTestsInSeparateProcesses
 */
final class LivePreviewTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'field', 'filter', 'text', 'node', 'headless_frontend_editing'];

  /**
   * The unsaved form values of a node go to the node preview tempstore.
   */
  public function testNodeRoot(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['system', 'node', 'filter', 'headless_frontend_editing']);
    $this->installSchema('node', ['node_access']);
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $this->setUpCurrentUser([], ['access content', 'edit any article content']);
    $node = Node::create(['type' => 'article', 'title' => 'Saved']);
    $node->save();

    $form_object = $this->container->get('entity_type.manager')->getFormObject('node', 'headless_editing')->setEntity($node);
    $form_state = (new FormState())->set('hfe', TRUE);
    $form = $this->container->get('form_builder')->buildForm($form_object, $form_state);
    $form_state->setValue(['title', 0, 'value'], 'Typing');
    // A validation error of this request, as the form error handler shows it,
    // and an unrelated error that must stay.
    $form_state->setErrorByName('title', 'Title is required.');
    $this->container->get('messenger')->addError('Unrelated.');
    $this->container->get('messenger')->addError('Title is required.');

    $response = $this->container->get(LivePreview::class)->callback($form, $form_state);

    $key = 'hfe-live-' . $node->uuid();
    $this->assertSame([['command' => 'hfeLivePreview', 'path' => "/node/preview/$key/full"]], $response->getCommands());
    $preview = $this->container->get('tempstore.private')->get('node_preview')->get($key);
    $this->assertSame('Typing', $preview->getFormObject()->getEntity()->label());
    $this->assertSame('Saved', Node::load($node->id())->label());
    $this->assertSame(['Unrelated.'], array_map('strval', $this->container->get('messenger')->messagesByType('error')));

    // Saving removes the live preview.
    EntityFormHooks::redirectToSaved($form, $form_state);
    $this->assertNull($this->container->get('tempstore.private')->get('node_preview')->get($key));
  }

}
