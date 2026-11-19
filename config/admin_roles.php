<?php

return [
    'super_admin' => ['name' => 'Super Admin', 'description' => 'Quản lý tài khoản quản trị, phân quyền và truy cập toàn hệ thống.', 'permissions' => ['super', 'commerce', 'logistics']],
    'logistics_admin' => ['name' => 'Admin vận chuyển', 'description' => 'Quản lý đơn vị, nhân viên, phân công vận đơn và xử lý giao nhận.', 'permissions' => ['logistics']],
    'commerce_admin' => ['name' => 'Admin mua bán', 'description' => 'Quản lý người mua, người bán, sản phẩm, đơn hàng và voucher.', 'permissions' => ['commerce']],
];
