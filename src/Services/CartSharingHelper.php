<?php

declare(strict_types=1);

namespace Drupal\commerce_share_cart\Services;

use Drupal\Component\Datetime\Time;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\Url;
use Drupal\commerce_order\Entity\OrderInterface;

/**
 * The CartSharingHelper service.
 */
class CartSharingHelper {

  /**
   * Constructor of the CartSharingHelper service.
   *
   * @param \Drupal\Component\Datetime\Time $time
   *   The time service.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager.
   */
  public function __construct(
    protected Time $time,
    protected LanguageManagerInterface $languageManager,
  ) {}

  /**
   * Creates a sharing token for the order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $cart
   *   The order cart.
   * @param int $timestamp
   *   A UNIX timestamp, typically \Drupal::time()->getRequestTime().
   *
   * @return string
   *   The verification token.
   *
   * @see user_pass_rehash()
   */
  public function getCartSharingToken(OrderInterface $cart, int $timestamp): string {
    $data = $timestamp;
    $data .= ':' . $cart->id();
    $data .= ':' . $cart->label();
    $data .= ':' . $cart->uuid();
    $data .= ':' . $cart->getState()->getId();

    return Crypt::hmacBase64($data, Settings::getHashSalt());
  }

  /**
   * Validate sharing token for the order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $cart
   *   The order cart.
   * @param int $timestamp
   *   A UNIX timestamp, typically \Drupal::time()->getRequestTime().
   * @param string $hash
   *   The verification token.
   *
   * @return bool
   *   TRUE if token is valid for the user, FALSE in another case.
   */
  public function validateToken(OrderInterface $cart, int $timestamp, string $hash): bool {
    return hash_equals($hash, $this->getCartSharingToken($cart, $timestamp));
  }

  /**
   * Generate cart sharing URL.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $cart
   *   The order cart.
   *
   * @return \Drupal\Core\Url
   *   The cart sharing URL.
   */
  public function generateSharingUrl(OrderInterface $cart): Url {
    $timestamp = $this->time->getRequestTime();

    return Url::fromRoute(
      'commerce_share_cart.share_cart_confirm',
      [
        'cart' => $cart->id(),
        'timestamp' => $timestamp,
        'hash' => $this->getCartSharingToken($cart, $timestamp),
      ],
      [
        'absolute' => TRUE,
        'language' => $this->languageManager->getCurrentLanguage(),
      ]
    );
  }

}
