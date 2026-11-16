<?php

declare(strict_types=1);

namespace Drupal\Tests\headless_frontend_editing\Kernel;

use Drupal\headless_frontend_editing\Controller\ProtocolController;
use Drupal\headless_frontend_editing\PreviewToken;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\content_moderation\Traits\ContentModerationTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the describe and token endpoints.
 *
 * @group headless_frontend_editing
 * @runTestsInSeparateProcesses
 */
final class DescribeTest extends KernelTestBase {

  use ContentModerationTestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'filter', 'text', 'node', 'headless_frontend_editing', 'headless_frontend_editing_test_access',
  ];

  /**
   * The node described.
   */
  private Node $node;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['node']);
    $this->installSchema('node', ['node_access']);
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $this->node = Node::create(['type' => 'article', 'title' => 'Hello > world']);
    $this->node->save();
  }

  /**
   * Describes the given ids, as the current user.
   *
   * @param array<mixed> $targets
   *   The request items.
   *
   * @return array<mixed>
   *   The described targets.
   */
  private function describe(array $targets): array {
    $controller = ProtocolController::create($this->container);
    $response = $controller->describe(new Request(content: json_encode(['targets' => $targets])));
    return json_decode($response->getContent(), TRUE)['targets'];
  }

  /**
   * Tests an editor's description.
   */
  public function testEditor(): void {
    $this->setUpCurrentUser([], ['access content', 'edit any article content']);
    $uuid = $this->node->uuid();
    $result = $this->describe([
      ['id' => "node:$uuid:title", 'lang' => 'en'],
      ['id' => "node:$uuid", 'lang' => 'en'],
      ['id' => 'garbage', 'lang' => 'en'],
      ['id' => 5],
      'nope',
    ]);
    $this->assertCount(2, $result);
    $this->assertSame(['id' => 'edit', 'label' => 'Edit Title', 'disabled' => FALSE], $result[0]['actions'][0]);
    $this->assertSame('Title', $result[0]['label']);
    // Plain text, not HTML: the client sets labels with textContent.
    $this->assertSame('Hello > world (Article)', $result[1]['label']);
    $this->assertSame('Edit Hello > world', $result[1]['actions'][0]['label']);
  }

  /**
   * Tests that missing update access omits the target.
   */
  public function testNoAccess(): void {
    $this->setUpCurrentUser();
    $this->assertSame([], $this->describe([['id' => 'node:' . $this->node->uuid(), 'lang' => 'en']]));
  }

  /**
   * Tests that a denied field is omitted.
   */
  public function testFieldAccessDenied(): void {
    $this->setUpCurrentUser([], ['access content', 'edit any article content']);
    $uuid = $this->node->uuid();
    $result = $this->describe([
      ['id' => "node:$uuid:promote", 'lang' => 'en'],
      ['id' => "node:$uuid:title", 'lang' => 'en'],
    ]);
    $this->assertSame(["node:$uuid:title"], array_column($result, 'id'));
  }

  /**
   * Tests that a node with a pending draft is not offered.
   */
  public function testPendingDraft(): void {
    $this->enableModules(['workflows', 'content_moderation']);
    $this->installEntitySchema('content_moderation_state');
    $this->installConfig(['content_moderation']);
    $workflow = $this->createEditorialWorkflow();
    $this->addEntityTypeAndBundleToWorkflow($workflow, 'node', 'article');
    $this->setUpCurrentUser([], [
      'access content',
      'edit any article content',
      'use editorial transition create_new_draft',
      'use editorial transition publish',
    ]);
    $node = Node::create(['type' => 'article', 'title' => 'Live', 'moderation_state' => 'published']);
    $node->save();
    $targets = [
      ['id' => 'node:' . $node->uuid(), 'lang' => 'en'],
      ['id' => 'node:' . $node->uuid() . ':title', 'lang' => 'en'],
    ];
    $this->assertCount(2, $this->describe($targets));

    $node->setTitle('Draft')->set('moderation_state', 'draft')->save();
    $this->container->get('entity_type.manager')->getStorage('node')->resetCache();
    $this->assertSame([], $this->describe($targets));
  }

  /**
   * Tests the token endpoint.
   */
  public function testTokenFresh(): void {
    $user = $this->setUpCurrentUser([], ['use headless frontend editing']);
    $data = json_decode(ProtocolController::create($this->container)->token()->getContent(), TRUE);
    $this->assertSame((int) $user->id(), $this->container->get(PreviewToken::class)->validate($data['token']));
    $access = $this->container->get('access_manager');
    $this->assertFalse($access->checkNamedRoute('headless_frontend_editing.token', [], $this->createUser()));
    $this->assertTrue($access->checkNamedRoute('headless_frontend_editing.token', [], $user));
  }

}
