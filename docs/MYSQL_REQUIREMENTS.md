# Hệ thống thương mại điện tử dùng MySQL

## 1. Phạm vi đã triển khai

Backend giữ kiến trúc Laravel và đã được nâng lên Laravel 12/PHP 8.2. Cơ sở dữ liệu chính là MySQL. Hệ thống hiện có:

- Khách hàng, người bán, cửa hàng, danh mục, sản phẩm và biến thể sản phẩm.
- Giỏ hàng, đơn hàng nhiều sản phẩm, thanh toán và vận chuyển.
- Năm kho, tồn kho theo kho và lịch sử nhập/xuất/hoàn kho.
- Voucher, giới hạn lượt dùng và lịch sử sử dụng.
- Yêu thích, đánh giá và lịch sử thay đổi giá.
- Luồng trạng thái đơn hàng: Processing -> Ready -> Delivering -> Delivered; hỗ trợ Cancelled và Return.
- Giao dịch MySQL có `lockForUpdate`, trừ/hoàn kho đúng một lần và retry khi có xung đột.
- View, stored procedure và 20 loại báo cáo quản trị.
- Cache báo cáo qua Redis; tự chuyển sang truy vấn trực tiếp nếu Redis không hoạt động.

## 2. Dữ liệu mẫu hiện tại

| Bảng/nghiệp vụ | Số lượng |
|---|---:|
| Khách hàng | 100 |
| Cửa hàng | 20 |
| Sản phẩm | 500 |
| Kho | 5 |
| Đơn hàng | 1.000 |
| Chi tiết đơn hàng | 3.000 |
| Thanh toán | 1.000 |
| Đánh giá | 500 |
| Voucher | 20 |
| Yêu thích | 200 |
| Lịch sử giá | 100 |

## 3. Chạy backend không dùng Docker

Yêu cầu: PHP 8.2+, Composer và MySQL 8.x. Trong MySQL Workbench, đăng nhập bằng tài khoản quản trị và chạy:

```sql
CREATE DATABASE IF NOT EXISTS e_commerce
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'e_commerce'@'localhost'
    IDENTIFIED BY 'e_commerce';
CREATE USER IF NOT EXISTS 'e_commerce'@'127.0.0.1'
    IDENTIFIED BY 'e_commerce';

ALTER USER 'e_commerce'@'localhost' IDENTIFIED BY 'e_commerce';
ALTER USER 'e_commerce'@'127.0.0.1' IDENTIFIED BY 'e_commerce';

GRANT ALL PRIVILEGES ON e_commerce.* TO 'e_commerce'@'localhost';
GRANT ALL PRIVILEGES ON e_commerce.* TO 'e_commerce'@'127.0.0.1';

-- Cần cho các migration tạo trigger khi MySQL bật binary log.
SET GLOBAL log_bin_trust_function_creators = 1;
FLUSH PRIVILEGES;
```

Thiết lập `.env`:

```dotenv
APP_URL=http://127.0.0.1:9000
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=e_commerce
DB_USERNAME=e_commerce
DB_PASSWORD=e_commerce

# Máy không cài Redis vẫn chạy được bằng file cache.
CACHE_DRIVER=file
REPORT_CACHE_STORE=file
REDIS_CLIENT=predis
```

Chạy ứng dụng:

```powershell
cd C:\Users\vietanh\Desktop\e-commerce-api
composer install
php artisan key:generate
php artisan optimize:clear
php artisan migrate --seed
php artisan storage:link
php artisan serve --host=127.0.0.1 --port=9000
```

Nếu dữ liệu đã được tạo như hiện tại thì không cần chạy lại `migrate --seed`; chỉ cần `php artisan serve`. Không dùng `migrate:fresh` nếu muốn giữ dữ liệu vì lệnh đó xóa toàn bộ bảng.

Tài khoản mẫu, tất cả dùng mật khẩu `password`:

- Quản trị: `admin01@gmail.com`.
- Khách hàng: `user01@gmail.com` hoặc `customer001@example.com`.

## 4. Chạy frontend

Frontend ở `C:\Users\vietanh\Desktop\e-shopee`. File `.env.local` cần có:

```dotenv
VITE_BASE_DOMAIN=http://127.0.0.1:9000
```

Mở PowerShell thứ hai:

```powershell
cd C:\Users\vietanh\Desktop\e-shopee
pnpm approve-builds
pnpm rebuild
pnpm dev
```

Trong màn hình `approve-builds`, tối thiểu cho phép `esbuild`, `vue-demi`, `swiper` và `vue-echarts`; `cypress` chỉ cần khi chạy kiểm thử giao diện. Truy cập `http://localhost:3333`.

## 5. API quản trị và báo cáo

Các endpoint bên dưới có tiền tố `/api/v2` và yêu cầu Bearer token quản trị:

- `GET /admin/dashboard`
- `GET /admin/reports/cache-benchmark`
- `GET /admin/reports/{report}`

Giá trị `{report}` hỗ trợ:

`top-selling`, `top-revenue`, `seller-revenue`, `monthly-revenue`, `customer-spending`, `top-customers`, `unsold-products`, `low-stock`, `sell-through`, `customer-history`, `order-timeline`, `top-rated`, `seller-problem-rates`, `monthly-order-rates`, `category-revenue`, `seller-monthly`, `repeat-category-customers`, `voucher-statistics`, `invalid-vouchers`, `price-history`.

Các nhóm API mới khác gồm `/shop/warehouses`, `/shop/inventory/adjust`, `/admin/vouchers`, `/admin/customers`, `/admin/sellers`, `/user/favorites`, `/user/payments/{payment}/pay` và API cập nhật trạng thái đơn hàng.

## 6. Kiểm tra

```powershell
composer audit
php artisan migrate:status
php artisan route:list --path=api/v2
php artisan test
```

File sao lưu trước khi nâng cấp schema nằm tại `storage/app/e_commerce_before_requirement_upgrade.sql`.
