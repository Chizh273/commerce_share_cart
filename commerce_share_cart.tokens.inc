<?php

/**
 * @file
 * Provides Token integration for Address.
 */

use Drupal\Core\Render\BubbleableMetadata;
use Drupal\commerce_order\Entity\OrderInterface;

/**
 * Implements hook_token_info().
 */
function commerce_share_cart_token_info(): array {
  $info['tokens']['commerce_order']['cart_sharing_link'] = [
    'name' => t('Cart share link'),
    'description' => t('The cart sharing link.'),
  ];

  return $info;
}

/**
 * Implements hook_tokens().
 */
function commerce_share_cart_tokens($type, $tokens, array $data, array $options, BubbleableMetadata $bubbleable_metadata): array {
  $replacements = [];

  if ($type === 'commerce_order' && isset($data['commerce_order']) && $data['commerce_order'] instanceof OrderInterface) {
    /** @var \Drupal\commerce_share_cart\Services\CartSharingHelper $cart_sharing_helper */
    $cart_sharing_helper = \Drupal::service('commerce_share_cart.cart_sharing_helper');

    foreach ($tokens as $name => $original) {
      if ($name == 'cart_sharing_link') {
        $replacements[$original] = $cart_sharing_helper
          ->generateSharingUrl($data['commerce_order'])
          ->toString();
      }
    }
  }

  return $replacements;
}
