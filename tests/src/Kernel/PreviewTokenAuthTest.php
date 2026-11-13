<?php

declare(strict_types=1);

namespace Drupal\Tests\headless_frontend_editing\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\PageCache\ChainRequestPolicy;
use Drupal\Core\PageCache\RequestPolicy\NoSessionOpen;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\graphql\Entity\Server;
use Drupal\headless_frontend_editing\Authentication\PreviewTokenAuth;
use Drupal\headless_frontend_editing\PreviewToken;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the read-only preview token authentication provider.
 */
#[Group('headless_frontend_editing')]
#[RunTestsInSeparateProcesses]
final class PreviewTokenAuthTest extends KernelTestBase {

  use UserCreationTrait;

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
    'file',
    'serialization',
    'jsonapi',
    'typed_data',
    'graphql',
    'page_cache',
    'headless_frontend_editing',
  ];

  /**
   * An editor who may see unpublished content.
   */
  private UserInterface $editor;

  /**
   * An unpublished article.
   */
  private NodeInterface $node;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    // The default policy refuses to cache on the command line: use the tagged
    // policies plus core's session check, as on a web server.
    $container->getDefinition('page_cache_request_policy')
      ->setClass(ChainRequestPolicy::class)
      ->setArguments([]);
    $container->register('test.page_cache_no_session', NoSessionOpen::class)
      ->addArgument(new Reference('session_configuration'))
      ->addTag('page_cache_request_policy');
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'node', 'filter']);
    $this->config('jsonapi.settings')->set('read_only', FALSE)->save();
    $this->config('system.performance')->set('cache.page.max_age', 300)->save();

    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    Server::create([
      'name' => 'preview',
      'label' => 'Preview',
      'schema' => 'composable',
      'endpoint' => '/graphql',
      'schema_configuration' => ['composable' => ['extensions' => []]],
    ])->save();
    $this->container->get('router.builder')->rebuild();

    // Keep the editor off uid 1.
    $this->createUser();
    $this->editor = $this->createUser([
      'access content',
      'bypass node access',
      'execute preview arbitrary graphql requests',
    ]);
    $this->node = Node::create([
      'type' => 'article',
      'title' => 'Draft',
      'status' => NodeInterface::NOT_PUBLISHED,
    ]);
    $this->node->save();
  }

  /**
   * A GET on JSON:API with a valid token authenticates.
   */
  public function testJsonApiGetAuthenticates(): void {
    $response = $this->request('GET', $this->jsonApiPath(), $this->token($this->editor));
    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

    $response = $this->request('GET', $this->jsonApiPath());
    $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
  }

  /**
   * A JSON:API write with a valid token stays anonymous.
   */
  public function testJsonApiWriteIgnored(): void {
    $body = json_encode([
      'data' => [
        'type' => 'node--article',
        'id' => $this->node->uuid(),
        'attributes' => ['title' => 'Changed'],
      ],
    ], JSON_THROW_ON_ERROR);
    $response = $this->request('PATCH', $this->jsonApiPath(), $this->token($this->editor), $body, 'application/vnd.api+json');

    $this->assertNotSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertTrue($this->container->get('current_user')->isAnonymous());
    $this->assertSame('Draft', Node::load($this->node->id())->getTitle());
  }

  /**
   * A GraphQL mutation is anonymous; a query is authenticated.
   */
  public function testGraphqlMutationIgnored(): void {
    $token = $this->token($this->editor);
    $cases = [
      'query { __typename }' => (int) $this->editor->id(),
      '{ __typename }' => (int) $this->editor->id(),
      'mutation { doIt }' => 0,
      'query a { __typename } mutation b { doIt }' => 0,
      'query {' => 0,
    ];
    foreach ($cases as $document => $uid) {
      $body = json_encode(['query' => $document], JSON_THROW_ON_ERROR);
      $this->request('POST', '/graphql', $token, $body, 'application/json');
      $this->assertSame($uid, (int) $this->container->get('current_user')->id(), $document);
    }

    // A batch with one mutation is anonymous too.
    $body = json_encode([['query' => '{ __typename }'], ['query' => 'mutation { doIt }']], JSON_THROW_ON_ERROR);
    $this->request('POST', '/graphql', $token, $body, 'application/json');
    $this->assertTrue($this->container->get('current_user')->isAnonymous());

    // A request without a document (a persisted query id) is anonymous.
    $this->request('POST', '/graphql', $token, '{"queryId":"abc"}', 'application/json');
    $this->assertTrue($this->container->get('current_user')->isAnonymous());
  }

  /**
   * Bodies that hide a mutation from the pre-routing check are refused.
   */
  public function testGraphqlBypassBodiesRefused(): void {
    $token = $this->token($this->editor);
    $query = $this->request('POST', '/graphql', $token, '{"query":"{ __typename }"}', 'application/json');
    $this->assertNotSame(Response::HTTP_FORBIDDEN, $query->getStatusCode());

    // graphql-php lowercases keys, the last one wins.
    $body = '{"query":"{ __typename }","QUERY":"mutation { doIt }"}';
    $response = $this->request('POST', '/graphql', $token, $body, 'application/json');
    $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    $response = $this->request('POST', '/graphql', $token, "[$body]", 'application/json');
    $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());

    // The graphql module merges form fields into a JSON body.
    $body = '{"query":"{ __typename }","x":"&query=mutation{ doIt }&"}';
    parse_str($body, $post);
    $response = $this->request('POST', '/graphql', $token, $body, 'application/x-www-form-urlencoded; x=multipart/form-data', ['Apollo-Require-Preflight' => '1'], $post);
    $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());

    // A persisted query id is refused even next to a document.
    $response = $this->request('POST', '/graphql', $token, '{"query":"{ __typename }","id":"abc"}', 'application/json');
    $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
  }

  /**
   * A token response is neither page cached nor publicly cacheable.
   */
  public function testPageCache(): void {
    $response = $this->request('GET', $this->jsonApiPath(), $this->token($this->editor));
    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    $this->assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));

    $response = $this->request('GET', $this->jsonApiPath());
    $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    $this->assertStringNotContainsString('Draft', (string) $response->getContent());
  }

  /**
   * A token for a blocked user is anonymous.
   */
  public function testInactiveUser(): void {
    $token = $this->token($this->editor);
    $this->editor->block()->save();

    $response = $this->request('GET', $this->jsonApiPath(), $token);
    $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    $this->assertTrue($this->container->get('current_user')->isAnonymous());
  }

  /**
   * The provider is not global: other routes reject the header.
   */
  public function testNotOnOtherRoutes(): void {
    $response = $this->request('GET', '/node/' . $this->node->id(), $this->token($this->editor));
    $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
  }

  /**
   * Returns the JSON:API path of the test node.
   */
  private function jsonApiPath(): string {
    return '/jsonapi/node/article/' . $this->node->uuid();
  }

  /**
   * Returns a preview token for an account.
   */
  private function token(UserInterface $account): string {
    return $this->container->get(PreviewToken::class)->create($account);
  }

  /**
   * Sends a request through the HTTP kernel as an anonymous visitor.
   *
   * @param string $method
   *   The HTTP method.
   * @param string $path
   *   The path.
   * @param string|null $token
   *   A preview token to send, if any.
   * @param string|null $body
   *   The request body.
   * @param string|null $content_type
   *   The Content-Type header.
   * @param array<string, string> $headers
   *   Extra request headers.
   * @param array<string, mixed> $post
   *   Form fields, as PHP would parse them from the body.
   */
  private function request(string $method, string $path, ?string $token = NULL, ?string $body = NULL, ?string $content_type = NULL, array $headers = [], array $post = []): Response {
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    $server = $content_type ? ['CONTENT_TYPE' => $content_type] : [];
    $request = Request::create($path, $method, $post, [], [], $server, $body);
    $request->headers->add($headers);
    if ($token !== NULL) {
      $request->headers->set(PreviewTokenAuth::HEADER, $token);
    }
    return $this->container->get('http_kernel')->handle($request);
  }

}
