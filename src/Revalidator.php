<?php

declare(strict_types=1);

namespace Drupal\headless_frontend_editing;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\DestructableInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\TypedData\TranslatableInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Tells the frontend which content changed, once per request.
 */
final class Revalidator implements DestructableInterface {

  /**
   * Queued entities keyed by type, UUID and language.
   *
   * @var array<string, array{type: string, uuid: string, langcode: string}>
   */
  private array $queue = [];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ClientInterface $httpClient,
    #[Autowire(service: 'logger.channel.headless_frontend_editing')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Queues an entity when its type is configured to trigger revalidation.
   *
   * Every translation is queued: untranslatable fields change all of them,
   * and a deleted entity is gone in every language.
   */
  public function queue(EntityInterface $entity): void {
    $type = $entity->getEntityTypeId();
    $uuid = $entity->uuid();
    $types = $this->configFactory->get('headless_frontend_editing.settings')->get('revalidate_entity_types') ?? [];
    if ($uuid === NULL || !in_array($type, $types, TRUE)) {
      return;
    }
    $langcodes = $entity instanceof TranslatableInterface ? array_keys($entity->getTranslationLanguages()) : [$entity->language()->getId()];
    foreach ($langcodes as $langcode) {
      $this->queue["$type:$uuid:$langcode"] = ['type' => $type, 'uuid' => $uuid, 'langcode' => $langcode];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function destruct(): void {
    $entities = array_values($this->queue);
    $this->queue = [];
    $config = $this->configFactory->get('headless_frontend_editing.settings');
    $secret = Settings::get('headless_frontend_editing_revalidate_secret');
    $base = (string) $config->get('frontend_internal_url');
    $path = (string) $config->get('revalidate_path');
    if (!$entities || !$secret || $base === '' || $path === '') {
      return;
    }
    try {
      $this->httpClient->request('POST', rtrim($base, '/') . '/' . ltrim($path, '/'), [
        'json' => ['entities' => $entities],
        'headers' => ['X-HFE-Revalidate-Secret' => $secret],
        'timeout' => 5,
      ]);
    }
    catch (GuzzleException $e) {
      $this->logger->warning('Frontend revalidation failed: @message', ['@message' => $e->getMessage()]);
    }
  }

}
