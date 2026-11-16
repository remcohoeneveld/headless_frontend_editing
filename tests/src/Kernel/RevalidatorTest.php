<?php

declare(strict_types=1);

namespace Drupal\Tests\headless_frontend_editing\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Site\Settings;
use Drupal\headless_frontend_editing\Revalidator;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Log\LoggerInterface;

/**
 * Tests the on-demand frontend revalidation.
 *
 * @group headless_frontend_editing
 * @runTestsInSeparateProcesses
 */
final class RevalidatorTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'language',
    'headless_frontend_editing',
  ];

  /**
   * Requests sent by the HTTP client.
   *
   * @var \ArrayObject<int, array<string, mixed>>
   */
  private \ArrayObject $history;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $this->history = new \ArrayObject();
    $stack = HandlerStack::create(new MockHandler([
      new Response(200),
      new Response(200),
      new Response(200),
    ]));
    $stack->push(Middleware::history($this->history));
    $container->register('http_client', Client::class)
      ->addArgument(['handler' => $stack]);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['node', 'headless_frontend_editing']);
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    $this->config('headless_frontend_editing.settings')
      ->set('frontend_internal_url', 'http://frontend:3000')
      ->save();
    $this->setSecret('test-secret');
    // Setup saves must not count.
    $this->history->exchangeArray([]);
    $this->container->get(Revalidator::class)->destruct();
    $this->history->exchangeArray([]);
  }

  /**
   * Sets or clears the shared secret.
   */
  private function setSecret(?string $secret): void {
    $settings = Settings::getAll();
    $settings['headless_frontend_editing_revalidate_secret'] = $secret;
    new Settings($settings);
  }

  /**
   * Creates and saves a page.
   */
  private function page(string $title): Node {
    $node = Node::create(['type' => 'page', 'title' => $title]);
    $node->save();
    return $node;
  }

  /**
   * Two saves in one request give one POST with both entities.
   */
  public function testOnePostPerRequest(): void {
    $one = $this->page('One');
    $two = $this->page('Two');
    // Saving the same node twice must not duplicate it.
    $one->save();
    $this->assertCount(0, $this->history);

    $this->container->get(Revalidator::class)->destruct();

    $this->assertCount(1, $this->history);
    $request = $this->history[0]['request'];
    $this->assertSame('POST', $request->getMethod());
    $this->assertSame('http://frontend:3000/api/revalidate', (string) $request->getUri());
    $this->assertSame('test-secret', $request->getHeaderLine('X-HFE-Revalidate-Secret'));
    $body = json_decode((string) $request->getBody(), TRUE);
    $this->assertEqualsCanonicalizing([
      ['type' => 'node', 'uuid' => $one->uuid(), 'langcode' => 'en'],
      ['type' => 'node', 'uuid' => $two->uuid(), 'langcode' => 'en'],
    ], $body['entities']);

    // The queue is flushed: a second destruct sends nothing.
    $this->container->get(Revalidator::class)->destruct();
    $this->assertCount(1, $this->history);
  }

  /**
   * Updating or deleting a translated page queues every translation.
   */
  public function testAllTranslations(): void {
    $this->installConfig(['language']);
    ConfigurableLanguage::createFromLangcode('nl')->save();
    $node = $this->page('One');
    $node->addTranslation('nl', ['title' => 'Een'])->save();
    $expected = [
      ['type' => 'node', 'uuid' => $node->uuid(), 'langcode' => 'en'],
      ['type' => 'node', 'uuid' => $node->uuid(), 'langcode' => 'nl'],
    ];
    $revalidator = $this->container->get(Revalidator::class);
    $revalidator->destruct();
    $this->history->exchangeArray([]);

    $node->save();
    $revalidator->destruct();
    $node->delete();
    $revalidator->destruct();
    $this->assertCount(2, $this->history);
    foreach ($this->history as $entry) {
      $this->assertEqualsCanonicalizing($expected, json_decode((string) $entry['request']->getBody(), TRUE)['entities']);
    }
  }

  /**
   * Without a secret nothing is sent.
   */
  public function testNoSecretNoRequest(): void {
    $this->setSecret(NULL);
    $this->page('One');
    $this->container->get(Revalidator::class)->destruct();
    $this->assertCount(0, $this->history);
  }

  /**
   * Entity types outside the configured list are ignored.
   */
  public function testEntityTypeFilter(): void {
    User::create(['name' => 'someone', 'mail' => 'someone@example.com'])->save();
    $this->container->get(Revalidator::class)->destruct();
    $this->assertCount(0, $this->history);
  }

  /**
   * An unreachable frontend is logged as a warning, never thrown.
   */
  public function testFailureLogged(): void {
    $stack = HandlerStack::create(new MockHandler([
      new ConnectException('down', new Request('POST', 'http://frontend:3000')),
    ]));
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');
    $revalidator = new Revalidator(
      $this->container->get('config.factory'),
      new Client(['handler' => $stack]),
      $logger,
    );
    $revalidator->queue($this->page('One'));
    $revalidator->destruct();
  }

  /**
   * An empty revalidate path disables revalidation.
   */
  public function testEmptyRevalidatePathDisables(): void {
    $this->config('headless_frontend_editing.settings')->set('revalidate_path', '')->save();
    $this->page('One');
    $this->container->get(Revalidator::class)->destruct();
    $this->assertCount(0, $this->history);
  }

}
