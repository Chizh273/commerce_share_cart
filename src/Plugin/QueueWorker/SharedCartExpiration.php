<?php

namespace Drupal\commerce_share_cart\Plugin\QueueWorker;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\commerce\Interval;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Deletes expired shared carts.
 */
#[QueueWorker(
  id: SharedCartExpiration::PLUGIN_ID,
  title: new TranslatableMarkup('Cart shared expiration'),
  cron: ['time' => 30],
)]
class SharedCartExpiration extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  const string PLUGIN_ID = 'commerce_share_cart_expiration';

  /**
   * The order storage.
   */
  protected EntityStorageInterface $orderStorage;

  /**
   * The order type storage.
   */
  protected EntityStorageInterface $orderTypeStorage;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    $instance = new self($configuration, $plugin_id, $plugin_definition);
    $instance->orderStorage = $container->get('entity_type.manager')
      ->getStorage('commerce_order');
    $instance->orderTypeStorage = $container->get('entity_type.manager')
      ->getStorage('commerce_order_type');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $orders = [];
    foreach ($data as $order_id) {
      // Skip the OrderRefresh process to keep the changed timestamp intact.
      /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
      $order = $this->orderStorage->loadUnchanged($order_id);
      if (!$order) {
        continue;
      }
      /** @var \Drupal\commerce_order\Entity\OrderTypeInterface $order_type */
      $order_type = $this->orderTypeStorage->load($order->bundle());
      $cart_expiration = $order_type->getThirdPartySetting('commerce_share_cart', 'shared_cart_expiration');
      // Confirm that cart expiration has not been disabled after queueing.
      if (empty($cart_expiration)) {
        continue;
      }

      $current_date = new DrupalDateTime('now');
      $interval = new Interval($cart_expiration['number'], $cart_expiration['unit']);
      $expiration_date = $interval->subtract($current_date);
      $expiration_timestamp = $expiration_date->getTimestamp();
      // Make sure that the cart order still qualifies for expiration.
      if ($order->get('cart')->value && $order->getChangedTime() <= $expiration_timestamp) {
        $orders[] = $order;
      }
    }

    $this->orderStorage->delete($orders);
  }

}
