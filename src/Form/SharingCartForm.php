<?php

declare(strict_types=1);

namespace Drupal\commerce_share_cart\Form;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\CloseModalDialogCommand;
use Drupal\Core\Ajax\PrependCommand;
use Drupal\Core\Ajax\RemoveCommand;
use Drupal\Core\Config\Entity\ConfigEntityStorageInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManager;
use Drupal\Core\Render\Markup;
use Drupal\Core\Utility\Token;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a Commerce Share Cart form.
 */
class SharingCartForm extends FormBase {

  /**
   * The `commerce_order_type` storage.
   *
   * @var \Drupal\Core\Config\Entity\ConfigEntityStorageInterface
   */
  protected ConfigEntityStorageInterface $orderTypeStorage;

  /**
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailManager
   */
  protected MailManager $mailManager;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected LanguageManagerInterface $languageManager;

  /**
   * The token service.
   *
   * @var \Drupal\Core\Utility\Token
   */
  protected Token $token;

  /**
   * The order cart.
   *
   * @var \Drupal\commerce_order\Entity\OrderInterface|null
   */
  protected ?OrderInterface $cart = NULL;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    $instance = parent::create($container);
    $instance->token = $container->get('token');
    $instance->mailManager = $container->get('plugin.manager.mail');
    $instance->languageManager = $container->get('language_manager');
    $instance->orderTypeStorage = $container->get('entity_type.manager')
      ->getStorage('commerce_order_type');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'commerce_share_cart_sharing_cart';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, OrderInterface $cart = NULL): array {
    if ($cart === NULL) {
      return $form;
    }

    $this->cart = $cart;
    /** @var \Drupal\commerce_order\Entity\OrderTypeInterface $order_type */
    $order_type = $this->orderTypeStorage->load($this->cart->bundle());
    $modal_settings = $order_type->getThirdPartySetting('commerce_share_cart', 'modal');

    $form['message'] = [
      '#type' => 'processed_text',
      '#text' => $this->token->replace($modal_settings['text']['value'], ['commerce_order' => $cart],),
      '#format' => $modal_settings['text']['format'],
    ];

    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email'),
      '#required' => TRUE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Share'),
        '#ajax' => [
          'callback' => [$this, 'ajaxSubmit'],
        ],
      ],
    ];

    return $form;
  }

  /**
   * Submit form ajax callback.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   An AJAX response.
   */
  public function ajaxSubmit(array $form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();
    $response->addCommand(new RemoveCommand('[data-drupal-messages]'));
    $response->addCommand(new PrependCommand('.commerce-share-cart-form-' . $this->cart->id(), [
      '#type' => 'status_messages',
    ]));
    $response->addCommand(new CloseModalDialogCommand());
    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\commerce_order\Entity\OrderTypeInterface $order_type */
    $order_type = $this->orderTypeStorage->load($this->cart->bundle());

    if ($this->cart instanceof OrderInterface) {
      $email = $form_state->getValue('email');
      $shared_cart = $this->createSharedCart($this->cart, user_load_by_mail($email) ?: NULL);
      $mail_settings = $order_type->getThirdPartySetting('commerce_share_cart', 'mail');

      // Send cart-share mail.
      $message = $this->mailManager->mail(
        'commerce_share_cart',
        'cart_share',
        $form_state->getValue('email'),
        $this->languageManager->getCurrentLanguage()->getId(),
        [
          'subject' => $this->token->replace($mail_settings['subject'], ['commerce_order' => $shared_cart]),
          'body' => Markup::create($this->token->replace($mail_settings['body']['value'], ['commerce_order' => $shared_cart])),
        ]
      );
    }

    if (isset($message['result']) && $message['result']) {
      $this->messenger()
        ->addMessage($this->t('Your cart was successfully shared.'));
    }
    else {
      $this->messenger()
        ->addError($this->t('Something went wrong while sharing your cart.'));
    }
  }

  /**
   * Create shared cart.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $cart
   *   The cart.
   * @param \Drupal\user\UserInterface|null $user
   *   The user to which cart should be shared.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface
   *   The shared cart.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function createSharedCart(OrderInterface $cart, ?UserInterface $user = NULL): OrderInterface {
    $items = array_map(
      fn(OrderItem $item) => $item->createDuplicate()->set('order_id', NULL),
      $cart->getItems()
    );

    $shared_cart = $cart->createDuplicate()
      ->setEmail($user?->getEmail())
      ->setItems($items)
      ->set('state', 'shared')
      ->set('uid', $user?->id() ?: 0);

    $shared_cart->save();

    return $shared_cart;
  }

}
