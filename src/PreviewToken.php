<?php

declare(strict_types=1);

namespace Drupal\headless_frontend_editing;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\PrivateKey;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Site\Settings;

/**
 * Creates and validates signed, short-lived preview tokens.
 *
 * Token format: {uid}.{expires}.{hmac}.
 */
final class PreviewToken {

  /**
   * Token lifetime in seconds.
   */
  public const TTL = 600;

  public function __construct(
    private PrivateKey $privateKey,
    private TimeInterface $time,
  ) {}

  /**
   * Creates a token for the given account.
   */
  public function create(AccountInterface $account): string {
    $payload = $account->id() . '.' . ($this->time->getRequestTime() + self::TTL);
    return $payload . '.' . $this->sign($payload);
  }

  /**
   * Returns the uid a valid, unexpired token was issued for, or NULL.
   */
  public function validate(string $token): ?int {
    $parts = explode('.', $token);
    if (count($parts) !== 3 || !ctype_digit($parts[0]) || !ctype_digit($parts[1])) {
      return NULL;
    }
    [$uid, $expires, $signature] = $parts;
    if (!hash_equals($this->sign($uid . '.' . $expires), $signature)) {
      return NULL;
    }
    return (int) $expires >= $this->time->getRequestTime() ? (int) $uid : NULL;
  }

  /**
   * Signs a payload with the site private key and hash salt.
   *
   * Prefixed: core's CSRF tokens use the same key, and a signature for one
   * must never be valid for the other.
   */
  private function sign(string $payload): string {
    return Crypt::hmacBase64('headless_frontend_editing.preview:' . $payload, $this->privateKey->get() . Settings::getHashSalt());
  }

}
