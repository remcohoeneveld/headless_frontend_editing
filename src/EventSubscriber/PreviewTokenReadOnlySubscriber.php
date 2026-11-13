<?php

declare(strict_types=1);

namespace Drupal\headless_frontend_editing\EventSubscriber;

use Drupal\headless_frontend_editing\Authentication\PreviewTokenAuth;
use GraphQL\Error\Error;
use GraphQL\Language\Parser;
use GraphQL\Server\OperationParams;
use GraphQL\Utils\AST;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Refuses GraphQL operations other than queries for preview-token requests.
 *
 * Runs after routing, on the operations the graphql module itself parsed, so
 * what is checked is exactly what will be executed.
 */
final class PreviewTokenReadOnlySubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Below the router (32), so route enhancers have set "operations".
    return [KernelEvents::REQUEST => ['onRequest', 30]];
  }

  /**
   * Throws when a preview-token request carries a non-query operation.
   */
  public function onRequest(RequestEvent $event): void {
    $request = $event->getRequest();
    if ($request->attributes->get('_authentication_provider') !== PreviewTokenAuth::PROVIDER_ID
      || !$request->attributes->has('operations')) {
      return;
    }
    $operations = $request->attributes->get('operations');
    foreach (is_array($operations) ? $operations : [$operations] as $operation) {
      if (!$this->isQuery($operation)) {
        throw new AccessDeniedHttpException('Preview tokens only allow GraphQL queries.');
      }
    }
  }

  /**
   * Whether an operation is a query given as a document.
   */
  private function isQuery(mixed $operation): bool {
    if (!$operation instanceof OperationParams) {
      return FALSE;
    }
    // The properties are documented as strings but are NULL when absent.
    $values = get_object_vars($operation);
    if (isset($values['queryId']) || !is_string($values['query'] ?? NULL)) {
      return FALSE;
    }
    try {
      $document = Parser::parse($values['query']);
    }
    catch (Error) {
      return FALSE;
    }
    $name = $values['operation'] ?? NULL;
    if ($name !== NULL && !is_string($name)) {
      return FALSE;
    }
    return AST::getOperationAST($document, $name)?->operation === 'query';
  }

}
