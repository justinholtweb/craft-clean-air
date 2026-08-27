<?php

namespace justinholtweb\cleanair\types;

use Craft;
use craft\elements\User;

/**
 * Craft Commerce orders.
 *
 * Orders have one field layout, so there's no source to pick. What matters is the money and
 * the dates — "completed orders over £500 that haven't shipped", which is a report the
 * Commerce order index can't quite express.
 */
class CommerceOrderType extends CommerceType
{
    public function key(): string
    {
        return 'orders';
    }

    public function elementType(): string
    {
        return 'craft\\commerce\\elements\\Order';
    }

    public function label(): string
    {
        return Craft::t('cleanair', 'Orders');
    }

    public function requiresSource(): bool
    {
        return false;
    }

    public function nativeAttributes(): array
    {
        $commerce = $this->commerce();

        $statusOptions = [];
        $gatewayOptions = [];
        if ($commerce) {
            foreach ($commerce->getOrderStatuses()->getAllOrderStatuses() as $status) {
                $statusOptions[(string)$status->id] = $status->name;
            }
            foreach ($commerce->getGateways()->getAllGateways() as $gateway) {
                $gatewayOptions[(string)$gateway->id] = $gateway->name;
            }
        }

        $definitions = [
            $this->text('number', Craft::t('commerce', 'Number')),
            $this->text('reference', Craft::t('commerce', 'Reference')),
            $this->text('email', Craft::t('commerce', 'Email')),
            $this->text('couponCode', Craft::t('commerce', 'Coupon Code')),
            $this->text('origin', Craft::t('commerce', 'Origin')),
            $this->text('shippingMethodHandle', Craft::t('commerce', 'Shipping Method')),
            $this->boolean('isCompleted', Craft::t('commerce', 'Completed')),
            $this->boolean('isPaid', Craft::t('commerce', 'Paid')),
            $this->date('dateOrdered', Craft::t('commerce', 'Date Ordered')),
            $this->date('datePaid', Craft::t('commerce', 'Date Paid')),
            $this->money('totalPrice', Craft::t('commerce', 'Total Price')),
            $this->money('totalPaid', Craft::t('commerce', 'Total Paid')),
            $this->money('itemSubtotal', Craft::t('commerce', 'Item Subtotal')),
            $this->money('totalDiscount', Craft::t('commerce', 'Total Discount')),
            $this->money('totalTax', Craft::t('commerce', 'Total Tax')),
            $this->number('totalQty', Craft::t('commerce', 'Total Quantity')),
            $this->relation('customerId', Craft::t('commerce', 'Customer'), User::class),
        ];

        if ($statusOptions) {
            $definitions[] = $this->options('orderStatusId', Craft::t('commerce', 'Order Status'), $statusOptions);
        }
        if ($gatewayOptions) {
            $definitions[] = $this->options('gatewayId', Craft::t('commerce', 'Gateway'), $gatewayOptions);
        }

        return $definitions;
    }

    public function defaultColumns(): array
    {
        return ['id', 'reference', 'email', 'orderStatusId', 'totalPrice', 'dateOrdered'];
    }

    public function statusOptions(): array
    {
        // Orders don't use Craft's element statuses — `isCompleted` and the order status are
        // the axes that mean anything, and both are offered as attributes.
        return [];
    }

    public function canView(?User $user): bool
    {
        return $user !== null && ($user->admin || $user->can('commerce-manageOrders'));
    }
}
