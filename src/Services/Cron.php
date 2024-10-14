<?php

namespace Drupal\commerce_share_cart\Services;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\commerce\CronInterface;
use Drupal\commerce\Interval;
use Drupal\commerce_share_cart\Plugin\QueueWorker\SharedCartExpiration;
use Drupal\commerce_share_cart\SharedCartInterface;

/**
 * Default cron implementation.
 *
 * Queues abandoned shared carts for expiration (deletion).
 *
 * @see \Drupal\commerce_share_cart\Plugin\QueueWorker\SharedCartExpiration
 */
class Cron implements CronInterface {

  /**
   * The order storage.
   */
  protected EntityStorageInterface $orderStorage;

  /**
   * The order type storage.
   */
  protected EntityStorageInterface $orderTypeStorage;

  /**
   * The commerce_cart_expiration queue.
   */
  protected QueueInterface $queue;

  /**
   * Constructs a new Cron object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   *   The queue factory.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, QueueFactory $queue_factory) {
    $this->orderStorage = $entity_type_manager->getStorage('commerce_order');
    $this->orderTypeStorage = $entity_type_manager->getStorage('commerce_order_type');
    $this->queue = $queue_factory->get(SharedCartExpiration::PLUGIN_ID);
  }

  /**
   * {@inheritdoc}
   */
  public function run(): void {
    /** @var \Drupal\commerce_order\Entity\OrderTypeInterface[] $order_types */
    $order_types = $this->orderTypeStorage->loadMultiple();
    foreach ($order_types as $order_type) {
      $cart_expiration = $order_type->getThirdPartySetting('commerce_share_cart', 'shared_cart_expiration');
      if (empty($cart_expiration)) {
        continue;
      }

      $interval = new Interval($cart_expiration['number'], $cart_expiration['unit']);
      $all_order_ids = $this->getOrderIds($order_type->id(), $interval);
      foreach (array_chunk($all_order_ids, 50) as $order_ids) {
        $this->queue->createItem($order_ids);
      }
    }
  }

  /**
   * Gets the applicable order IDs.
   *
   * @param string $order_type_id
   *   The order type ID.
   * @param \Drupal\commerce\Interval $interval
   *   The expiration interval.
   *
   * @return array
   *   The order IDs.
   */
  protected function getOrderIds(string $order_type_id, Interval $interval): array {
    $current_date = new DrupalDateTime('now');
    $expiration_date = $interval->subtract($current_date);

    return $this->orderStorage->getQuery()
      ->condition('type', $order_type_id)
      ->condition('changed', $expiration_date->getTimestamp(), '<=')
      ->condition('state', SharedCartInterface::SHARED_CART_STATE)
      ->condition('cart', TRUE)
      ->range(0, 250)
      ->accessCheck(FALSE)
      ->addTag('commerce_share_cart_expiration')
      ->execute();
  }

}
