<?php
/**
 * Copyright (c) VenPosTPV. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/** A client-safe failure while preparing or redeeming a native cart rule. */
class CouponCheckoutException extends RuntimeException
{
    public function __construct(string $message, private int $httpStatus = 422)
    {
        parent::__construct($message);
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}

/** Shared cart construction for ordinary orders and future coupon previews. */
class CouponCheckout
{
    public function __construct(private string $secret, private ?Context $context = null)
    {
        $this->context ??= Context::getContext();
    }

    /** Shared cart construction for ordinary orders and coupon previews. */
    public function buildCart(array $data): Cart
    {
        $idCustomer = (int) $data['id_customer'];
        $customer = new Customer($idCustomer);
        $cart = new Cart();
        $cart->id_customer = $idCustomer;
        $cart->id_currency = (int) $data['id_currency'];
        $cart->id_shop = (int) $data['id_shop'];
        $cart->id_lang = (int) $this->context->language->id;
        $cart->secure_key = $customer->secure_key;

        $addresses = $customer->getAddresses((int) $this->context->language->id);
        if (!empty($addresses)) {
            $firstAddress = reset($addresses);
            $cart->id_address_delivery = (int) $firstAddress['id_address'];
            $cart->id_address_invoice = (int) $firstAddress['id_address'];
        } else {
            $address = new Address();
            $address->id_customer = $idCustomer;
            $address->firstname = 'TPV';
            $address->lastname = 'Cliente';
            $address->address1 = 'TPV Local';
            $address->city = 'TPV';
            $address->id_country = (int) Configuration::get('PS_COUNTRY_DEFAULT');
            $address->alias = 'tpv_temp_' . time();
            if (!$address->add()) {
                throw new RuntimeException('Temporary address could not be created');
            }
            $cart->id_address_delivery = (int) $address->id;
            $cart->id_address_invoice = (int) $address->id;
        }
        if (!$cart->save()) {
            throw new RuntimeException('Cart could not be created');
        }

        foreach ($data['products'] as $product) {
            if (!$cart->updateQty((int) $product['quantity'], (int) $product['id_product'], (int) ($product['id_product_attribute'] ?? 0))) {
                throw new RuntimeException('Product could not be added to cart');
            }
        }
        return $cart;
    }
}
