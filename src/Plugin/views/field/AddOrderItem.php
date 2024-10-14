<?php

namespace Drupal\commerce_share_cart\Plugin\views\field;

use Drupal\Core\Config\Entity\ConfigEntityStorageInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\commerce_cart\CartManagerInterface;
use Drupal\commerce_cart\CartProviderInterface;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\Plugin\views\field\UncacheableFieldHandlerTrait;
use Drupal\views\Plugin\views\style\Table;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a form element for adding the order item to current user cart.
 */
#[ViewsField(AddOrderItem::PLUGIN_ID)]
class AddOrderItem extends FieldPluginBase {

  use UncacheableFieldHandlerTrait;

  const string PLUGIN_ID = 'commerce_share_cart_add_item';

  /**
   * The cart manager.
   */
  protected CartManagerInterface $cartManager;

  /**
   * The commerce_order_type entity storage.
   */
  protected ConfigEntityStorageInterface $orderTypeStorage;

  /**
   * The commerce_order_type entity storage.
   */
  protected ConfigEntityStorageInterface $orderItemTypeStorage;

  /**
   * The cart provider.
   */
  protected CartProviderInterface $cartProvider;

  /**
   * The current user.
   */
  protected AccountProxyInterface $currentUser;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->cartManager = $container->get('commerce_cart.cart_manager');
    $instance->orderTypeStorage = $container->get('entity_type.manager')
      ->getStorage('commerce_order_type');
    $instance->orderItemTypeStorage = $container->get('entity_type.manager')
      ->getStorage('commerce_order_item_type');
    $instance->messenger = $container->get('messenger');
    $instance->cartProvider = $container->get('commerce_cart.cart_provider');
    $instance->currentUser = $container->get('current_user');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions(): array {
    return parent::defineOptions() + [
      'delete_order_item_map' => ['default' => []],
      'delete_order_map' => ['default' => []],
      'redirect_to' => ['default' => '<front>'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state): void {
    $form['delete_order_map'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Delete order'),
      '#description' => $this->t('Select order types that should be deleted after adding their order items to the user cart'),
      '#options' => $this->mapEntityIdToEntityLabel($this->orderTypeStorage->loadByProperties()),
      '#default_value' => $this->options['delete_order_map'] ?? [],
    ];

    $form['delete_order_item_map'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Delete order items'),
      '#description' => $this->t('Select order item types that should be deleted after adding them to the user cart'),
      '#options' => $this->mapEntityIdToEntityLabel($this->orderItemTypeStorage->loadByProperties()),
      '#default_value' => $this->options['delete_order_item_map'] ?? [],
    ];

    $form['redirect_to'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Redirect to'),
      '#description' => $this->t('Enter the path where a user should be redirected after taking products to their cart'),
      '#default_value' => $this->options['redirect_to'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function clickSortable(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getValue(ResultRow $row, $field = NULL) {
    return '<!--form-item-' . $this->options['id'] . '--' . $row->index . '-->';
  }

  /**
   * {@inheritdoc}
   */
  public function elementLabelClasses($row_index = NULL): string {
    return !empty($this->view->style_plugin) && $this->view->style_plugin instanceof Table
      ? parent::elementLabelClasses($row_index) . ' select-all'
      : parent::elementLabelClasses($row_index);
  }

  /**
   * {@inheritdoc}
   */
  public function label() {
    return !empty($this->view->style_plugin) && $this->view->style_plugin instanceof Table
      ? [
        '#markup' => '<input type="checkbox" class="form-checkbox form-boolean form-boolean--type-checkbox"/>',
        '#attached' => ['library' => ['core/drupal.tableselect']],
      ]
      : parent::label();
  }

  /**
   * {@inheritdoc}
   */
  public function adminSummary(): string {
    return '';
  }

  /**
   * Form constructor for the views form.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function viewsForm(array &$form, FormStateInterface $form_state): void {
    // Make sure we do not accidentally cache this form.
    $form['#cache']['max-age'] = 0;
    // The view is empty, abort.
    if (empty($this->view->result)) {
      unset($form['actions']);
      return;
    }

    $form[$this->options['id']]['#tree'] = TRUE;
    foreach ($this->view->result as $row_index => $row) {
      /** @var \Drupal\commerce_order\Entity\OrderItemInterface $order_item */
      $order_item = $this->getEntity($row);

      $form[$this->options['id']][$row_index] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Add @product to cart', [
          '@product' => $order_item->getPurchasedEntity()
            ->label(),
        ]),
        '#title_display' => 'invisible',
        '#default_value' => FALSE,
      ];
    }

    $form['actions']['add_products'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add products'),
      '#add_items' => TRUE,
      '#show_update_message' => TRUE,
    ];
  }

  /**
   * Validate handler for the views form.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function viewsFormValidate(array &$form, FormStateInterface $form_state): void {
    $triggering_element = $form_state->getTriggeringElement();
    if (empty($triggering_element['#add_items'])) {
      // Don't run when the non "Add products" button is pressed.
      return;
    }

    $rows = $form_state->getValue($this->options['id'], []);
    foreach ($rows as $status) {
      // Check whether at least one product is selected.
      if ($status) {
        return;
      }
    }

    $form_state->setError($form, $this->t('Select at least one product for adding to your cart.'));
  }

  /**
   * Submit handler for the views form.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function viewsFormSubmit(array &$form, FormStateInterface $form_state): void {
    $triggering_element = $form_state->getTriggeringElement();
    if (empty($triggering_element['#add_items'])) {
      // Don't run when the non "Add products" button is pressed.
      return;
    }

    $rows = $form_state->getValue($this->options['id'], []);
    $save_cart = FALSE;
    $carts_map = [];

    foreach ($rows as $row_index => $selected) {
      if (!$selected) {
        // Skip non-selected rows.
        continue;
      }

      /** @var \Drupal\commerce_order\Entity\OrderItemInterface $order_item */
      $order_item = $this->getEntity($this->view->result[$row_index]);
      $order = $order_item->getOrder();
      $carts_map[$order->bundle()] = $order;
      $cart = $this->cartProvider->getCart($order->bundle(), $order->getStore())
        ?: $this->cartProvider->createCart($order->bundle(), $order->getStore());

      $this->cartManager->addOrderItem(
        $cart,
        $order_item->createDuplicate()->set('order_id', NULL),
      );

      if (!empty($this->options['delete_order_item_map'][$order_item->bundle()])) {
        $order_item->delete();
      }

      $save_cart = TRUE;
    }

    // Delete orders if their removal was enabled in the plugin configuration.
    foreach ($carts_map as $order_type_id => $cart) {
      if (!empty($this->options['delete_order_map'][$order_type_id])) {
        $cart->delete();
      }
    }

    if ($save_cart && !empty($triggering_element['#show_update_message'])) {
      $this->messenger->addMessage($this->t('Your shopping cart has been updated.'));
    }

    if (!empty($this->options['redirect_to'])) {
      $form_state->setRedirectUrl(Url::fromUserInput($this->options['redirect_to']));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    // Do nothing.
  }

  /**
   * Generate map of entity ID to entity label.
   *
   * @param array $entities
   *   Array of entities.
   *
   * @return array
   *   The map where keys are entity IDs and values are entity labels.
   */
  protected function mapEntityIdToEntityLabel(array $entities): array {
    $map = [];
    foreach ($entities as $entity) {
      $map[$entity->id()] = $entity->label();
    }
    return $map;
  }

}
