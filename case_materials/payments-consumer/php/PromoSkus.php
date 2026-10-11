<?php

declare(strict_types=1);

namespace Korzina\Orders\Payments;

/**
 * The "special list" of the next-order discount campaign (MKT-2217).
 * The list is owned by marketing and changes with a release.
 */
final class PromoSkus
{
    private const SKUS = [
        'GR-104522' => true,
        'GR-104523' => true,
        'GR-104871' => true,
        'GR-105310' => true,
        'GR-105311' => true,
        'GR-107764' => true,
        'DR-200418' => true,
        'DR-200419' => true,
        'DR-200977' => true,
        'DR-201350' => true,
        'DR-203006' => true,
        'HH-310045' => true,
        'HH-310046' => true,
        'HH-310212' => true,
        'HH-311890' => true,
        'HH-312407' => true,
        'HH-312408' => true,
        'BT-450031' => true,
        'BT-450032' => true,
        'BT-450670' => true,
        'BT-451128' => true,
        'PT-520903' => true,
        'PT-520904' => true,
        'PT-521455' => true,
        'PT-523310' => true,
        'SN-600172' => true,
        'SN-600173' => true,
        'SN-600589' => true,
        'SN-601244' => true,
        'SN-602001' => true,
        'FZ-710336' => true,
        'FZ-710337' => true,
        'FZ-712850' => true,
        'KD-830019' => true,
        'KD-830420' => true,
        'KD-831777' => true,
    ];

    /**
     * @param list<Item> $items
     */
    public static function containsAny(array $items): bool
    {
        foreach ($items as $item) {
            if (isset(self::SKUS[$item->sku])) {
                return true;
            }
        }

        return false;
    }
}
