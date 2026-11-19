<?php

// Amounts use the same currency as the catalog and checkout (currently USD).
return [
    'featured-2026' => [
        'name' => 'Sản phẩm nổi bật 2026',
        'vouchers' => [
            ['code' => 'TOP2026', 'name' => 'Ưu đãi bộ sưu tập 2026', 'discount_type' => 'percentage', 'discount_value' => 15, 'minimum_order_value' => 50, 'maximum_discount' => 20, 'max_uses' => 300],
            ['code' => 'STYLE2026', 'name' => 'Nâng cấp phong cách sống', 'discount_type' => 'percentage', 'discount_value' => 10, 'minimum_order_value' => 25, 'maximum_discount' => 10, 'max_uses' => 500],
        ],
    ],
    'sale-11-11' => [
        'name' => '11.11 · Đại tiệc mua sắm',
        'vouchers' => [
            ['code' => 'SIEUSALE1111', 'name' => 'Siêu voucher 11.11', 'discount_type' => 'percentage', 'discount_value' => 20, 'minimum_order_value' => 75, 'maximum_discount' => 30, 'max_uses' => 111],
            ['code' => 'DEAL1111', 'name' => 'Chốt deal tháng 11', 'discount_type' => 'percentage', 'discount_value' => 11, 'minimum_order_value' => 30, 'maximum_discount' => 15, 'max_uses' => 500],
            ['code' => 'QUA1111', 'name' => 'Quà tặng mùa mua sắm', 'discount_type' => 'fixed', 'discount_value' => 11, 'minimum_order_value' => 100, 'maximum_discount' => 11, 'max_uses' => 200],
        ],
    ],
    'best-sellers' => [
        'name' => 'Sản phẩm bán chạy',
        'voucher_codes' => ['TOP2026', 'STYLE2026'],
    ],
    'tech-deals' => [
        'name' => 'Thế giới công nghệ',
        'voucher_codes' => ['SIEUSALE1111', 'DEAL1111', 'QUA1111'],
    ],
    'everyday-picks' => [
        'name' => 'Phụ kiện nổi bật',
        'voucher_codes' => ['TOP2026', 'STYLE2026'],
    ],
];
