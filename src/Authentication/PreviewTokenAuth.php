<?php

declare(strict_types=1);

namespace Drupal\headless_frontend_editing\Authentication;

use Drupal\Core\Authentication\AuthenticationProviderInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\headless_frontend_editing\PreviewToken;
use Drupal\user\UserInterface;
use GraphQL\Error\Error;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Language\Parser;
use Symfony\Component\HttpFoundation\Request;

/**
 * Authenticates read-only JSON:API and GraphQL requests by preview token.
 *
 * Authentication runs before routing, so requests are classified by method
 * and by the configured GraphQL server endpoints. Anything that could write
 * stays anonymous.
 */
final class PreviewTokenAuth implements AuthenticationProviderInterface {

  /**
   * The request header that carries the preview token.
   */
  public const HEADER = 'X-HFE-Preview-Token';

  public function __construct(
    private PreviewToken $previewToken,
    private EntityTypeManagerInterface $entityTypeManager,
    private ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(Request $request): bool {
    return $request->headers->has(self::HEADER);
  }

  /**
   * {@inheritdoc}
   */
  public function authenticate(Request $request): ?AccountInterface {
    $read_only = $this->isGraphqlEndpoint($request)
      ? $this->isGraphqlQuery($request)
      : in_array($request->getMethod(), ['GET', 'HEAD'], TRUE);
    if (!$read_only) {
      return NULL;
    }
    $uid = $this->previewToken->validate((string) $request->headers->get(self::HEADER));
    if (!$uid) {
      return NULL;
    }
    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    return $user instanceof UserInterface && $user->isActive() ? $user : NULL;
  }

  /**
   * Whether the request path is the endpoint of a GraphQL server.
   */
  private function isGraphqlEndpoint(Request $request): bool {
    $names = $this->configFactory->listAll('graphql.graphql_servers.');
    foreach ($this->configFactory->loadMultiple($names) as $server) {
      if ($server->get('endpoint') === $request->getPathInfo()) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Whether every operation in a GraphQL request is a query.
   *
   * Mirrors how graphql-php reads operations: GET from the URL, POST from a
   * JSON body (an object or a batch list). Anything else, such as multipart
   * file uploads, persisted query ids or unparsable documents, is refused.
   */
  private function isGraphqlQuery(Request $request): bool {
    if (!class_exists(Parser::class)) {
      return FALSE;
    }
    if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
      $operations = [$request->query->all()];
    }
    else {
      $body = json_decode($request->getContent(), TRUE);
      if (!is_array($body)) {
        return FALSE;
      }
      $operations = array_is_list($body) ? $body : [$body];
    }
    if (!$operations) {
      return FALSE;
    }
    foreach ($operations as $operation) {
      if (!is_array($operation) || !is_string($operation['query'] ?? NULL)) {
        return FALSE;
      }
      try {
        $document = Parser::parse($operation['query']);
      }
      catch (Error) {
        return FALSE;
      }
      foreach ($document->definitions as $definition) {
        if ($definition instanceof OperationDefinitionNode && $definition->operation !== 'query') {
          return FALSE;
        }
      }
    }
    return TRUE;
  }

}
