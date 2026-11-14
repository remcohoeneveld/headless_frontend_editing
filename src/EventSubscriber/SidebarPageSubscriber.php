<?php

declare(strict_types=1);

namespace Drupal\headless_frontend_editing\EventSubscriber;

use Drupal\Core\Controller\TitleResolverInterface;
use Drupal\Core\Render\BareHtmlPageRendererInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Renders the pages shown in the editing sidebar without the theme's page.
 *
 * The sidebar is a narrow iframe: admin toolbars, breadcrumbs and sticky
 * headers of the admin theme do not fit and duplicate the host page. Routes
 * with the _headless_frontend_editing_sidebar option get just their content
 * and messages, still styled by the active theme.
 */
final class SidebarPageSubscriber implements EventSubscriberInterface {

  public const OPTION = '_headless_frontend_editing_sidebar';

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly BareHtmlPageRendererInterface $bareHtmlPageRenderer,
    private readonly TitleResolverInterface $titleResolver,
  ) {}

  /**
   * Turns the render array of a sidebar route into a bare HTML page.
   */
  public function onView(ViewEvent $event): void {
    $request = $event->getRequest();
    $route = $this->routeMatch->getRouteObject();
    $result = $event->getControllerResult();
    // Ajax and dialog requests have a wrapper format: core renders those.
    if (!$route?->getOption(self::OPTION) || !is_array($result) || $request->query->has('_wrapper_format') || $request->getRequestFormat() !== 'html') {
      return;
    }
    // Like core's HtmlRenderer: a #title (confirm forms set one) wins.
    $title = $result['#title'] ?? $this->titleResolver->getTitle($request, $route);
    $event->setResponse($this->bareHtmlPageRenderer->renderBarePage($result, $title ?? '', 'headless_frontend_editing_sidebar'));
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Before MainContentViewSubscriber (priority 0).
    return [KernelEvents::VIEW => ['onView', 10]];
  }

}
