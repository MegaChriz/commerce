<?php

namespace Drupal\Tests\commerce_tax\Kernel;

use Drupal\commerce_order\Adjustment;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_order\Entity\OrderItemType;
use Drupal\commerce_price\Price;
use Drupal\commerce_store\Entity\Store;
use Drupal\commerce_tax\Plugin\Commerce\TaxType\EuropeanUnionVat;
use Drupal\commerce_tax\TaxableType;
use Drupal\Tests\commerce\Kernel\CommerceKernelTestBase;
use Drupal\profile\Entity\Profile;

/**
 * Tests if taxes respect order item adjustments.
 *
 * @group commerce
 *
 * @todo use a "generic" tax type instead of the specific European tax type.
 * @todo maybe use a custom adjustment, so the dependency on commerce_promotion
 * can be removed for this test.
 */
class TaxAdjustmentTest extends CommerceKernelTestBase {

  /**
   * The tax type plugin.
   *
   * @var \Drupal\commerce_tax\Plugin\Commerce\TaxType\TaxTypeInterface
   */
  protected $plugin;

  /**
   * A sample user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $user;

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = [
    'entity_reference_revisions',
    'profile',
    'state_machine',
    'path',
    'commerce_order',
    'commerce_product',
    'commerce_promotion',
    'commerce_tax',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    $this->installEntitySchema('profile');
    $this->installEntitySchema('commerce_order');
    $this->installEntitySchema('commerce_order_item');
    $this->installEntitySchema('commerce_promotion');
    $this->installConfig([
      'profile',
      'commerce_order',
      'commerce_product',
      'commerce_promotion',
    ]);

    // Order item types that doesn't need a purchasable entity, for simplicity.
    OrderItemType::create([
      'id' => 'test_physical',
      'label' => 'Test (Physical)',
      'orderType' => 'default',
      'third_party_settings' => [
        'commerce_tax' => ['taxable_type' => TaxableType::PHYSICAL_GOODS],
      ],
    ])->save();

    $user = $this->createUser();
    $this->user = $this->reloadEntity($user);

    $configuration = [
      '_entity_id' => 'european_union_vat',
      'display_inclusive' => TRUE,
    ];
    $this->plugin = EuropeanUnionVat::create($this->container, $configuration, 'european_union_vat', ['label' => 'EU VAT']);
  }

  /**
   * Tests tax calculation in combination with a product discount.
   */
  public function testTaxCalculationWithDiscount() {
    // Create an order.
    $order = $this->buildOrder('NL', 'NL');

    // Create an order item for this order.
    // The used tax rate is 21%. This means the following calculation at this
    // point:
    // Subtotal:         100.00 USD
    // Tax (21% of 100):  21.00 USD
    // Total:            121.00 USD
    $order_item = OrderItem::create([
      'type' => 'test_physical',
      'quantity' => 1,
      'unit_price' => new Price('100', 'USD'),
    ]);
    $order_item->save();

    // Add a price adjustment (discount) to the order item.
    // The discount is 40 USD. This should result into the following
    // calculation:
    // Subtotal:        100.00 USD
    // Discount:        -40.00 USD
    // New subtotal:     60.00 USD
    // Tax (21% of 60):  12.60 USD
    // Total:            72.60 USD
    $order_item->addAdjustment(new Adjustment([
      'type' => 'promotion',
      'label' => t('Discount'),
      'amount' => new Price('-40', 'USD'),
    ]));
    // @todo The next line is temporary commented out, as I'm not sure yet if
    // $order_item->getTotalPrice() should return the price inclusive discounts.
    //$this->assertEquals(new Price('60.00', 'USD'), $order_item->getTotalPrice());
    $order_item->save();

    // Add item to the order.
    $order->addItem($order_item);
    $order->save();

    // Apply tax to the order.
    $this->assertTrue($this->plugin->applies($order));
    $this->plugin->apply($order);

    // Assert the applied tax amount.
    // Expected: (100 - 40) * 0.21 = 12.60 USD.
    $adjustments = $order->collectAdjustments();
    $adjustment = reset($adjustments);
    $this->assertEquals('tax', $adjustment->getType());
    $this->assertEquals(t('VAT'), $adjustment->getLabel());
    $this->assertEquals(new Price('12.60', 'USD'), $adjustment->getAmount());

    // And assert the total price.
    // Expected: (100 - 40) * 1.21 = 72.60 USD.
    $this->assertEquals(new Price('72.60', 'USD'), $order->getTotalPrice());
  }

  /**
   * Builds an order for testing purposes.
   *
   * @param string $customer_country
   *   The country of the customer, in a two character abbreviation.
   * @param string $store_country
   *   The country of the customer, in a two character abbreviation.
   * @param array $store_registrations
   *   (optional) Store tax settings.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface
   *   The order.
   */
  protected function buildOrder($customer_country, $store_country, array $store_registrations = []) {
    $store = Store::create([
      'type' => 'default',
      'label' => 'My store',
      'address' => [
        'country_code' => $store_country,
      ],
      'prices_include_tax' => FALSE,
      'tax_registrations' => $store_registrations,
    ]);
    $store->save();
    $customer_profile = Profile::create([
      'type' => 'customer',
      'uid' => $this->user->id(),
      'address' => [
        'country_code' => $customer_country,
      ],
    ]);
    $customer_profile->save();
    $order = Order::create([
      'type' => 'default',
      'uid' => $this->user->id(),
      'store_id' => $store,
      'billing_profile' => $customer_profile,
      'order_items' => [],
    ]);
    $order->save();

    return $order;
  }

}
