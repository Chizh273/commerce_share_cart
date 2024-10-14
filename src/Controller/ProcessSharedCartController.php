<?php

declare(strict_types=1);

namespace Drupal\commerce_share_cart\Controller;

use Drupal\commerce\Interval;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_share_cart\Services\CartSharingHelper;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Config\Entity\ConfigEntityStorageInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DrupalDateTime;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Returns responses for Commerce Share Cart routes.
 */
class ProcessSharedCartController extends ControllerBase {

  /**
   * The `commerce_order_type` storage.
   *
   * @var \Drupal\Core\Config\Entity\ConfigEntityStorageInterface
   */
  protected ConfigEntityStorageInterface $orderTypeStorage;

  /**
   * The cart sharing link service.
   *
   * @var \Drupal\commerce_share_cart\Services\CartSharingHelper
   */
  protected CartSharingHelper $cartSharingLinkService;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    $instance = parent::create($container);
    $instance->orderTypeStorage = $container->get('entity_type.manager')
      ->getStorage('commerce_order_type');
    $instance->cartSharingLinkService = $container->get('commerce_share_cart.cart_sharing_helper');
    return $instance;
  }

  /**
   * Builds the response.
   */
  public function sharedCartPage(OrderInterface $cart): array {
    return [
      '#type' => 'view',
      '#name' => 'commerce_share_cart_form',
      '#display_id' => 'default',
      '#arguments' => [
        $cart->id(),
      ],
    ];
  }

  /**
   * Process shared cart access check.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $cart
   *   The order cart.
   * @param int $timestamp
   *   The current timestamp.
   * @param string $hash
   *   Login link hash.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   Access result.
   *
   * @see \Drupal\user\Controller\UserController::determineErrorRedirect
   */
  public function access(OrderInterface $cart, int $timestamp, string $hash): AccessResult {
    $order_type = $this->orderTypeStorage->load($cart->bundle());
    $expiration = $order_type->getThirdPartySetting('commerce_share_cart', 'shared_cart_expiration');
    $interval = new Interval($expiration['number'], $expiration['unit']);

    if ($interval->subtract(new DrupalDateTime('now'))->getTimestamp() > $timestamp) {
      return AccessResult::forbidden('The token link that has expired.');
    }
    elseif (
      !$this->currentUser()->hasPermission('access any shared cart')
      && !$cart->getCustomer()->isAnonymous()
      && $cart->getCustomer()->id() !== $this->currentUser->id()
    ) {
      return AccessResult::forbidden('You are not allowed to see others shared carts.');
    }
    elseif ($this->cartSharingLinkService->validateToken($cart, $timestamp, $hash)) {
      // The information provided is valid.
      return AccessResult::allowed();
    }

    return AccessResult::forbidden('The token link is no longer valid.');
  }

}
