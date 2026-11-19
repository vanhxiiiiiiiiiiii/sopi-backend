# Thanh toán và vận chuyển Shoppe

## Nguyên nhân ban đầu

Checkout gán cứng `payment_method: 'cod'`; `CreateOrderRequest` chỉ nhận COD và `OrderService` luôn tạo payment COD. Repository chưa có SDK, adapter hoặc webhook của cổng thanh toán. Trang quản lý thẻ cũ chỉ lưu thông tin thẻ, không tạo token thanh toán dùng được. Vì vậy một thẻ đã hiển thị trong tài khoản không đồng nghĩa có thể trừ tiền.

`UpdateProgressRequest` trước đây cho Seller cập nhật các trạng thái 3/4/6. `OrderWorkflowService` vừa đánh dấu giao thành công vừa tự chuyển COD sang paid. Hai trách nhiệm này đã được tách.

## Phạm vi đã thực hiện

- COD tiếp tục tạo đơn và payment pending. Lưu yêu cầu đặt hàng gồm cả phương thức/thẻ vào hash idempotency, giữ tương thích biên nhận COD cũ. Chọn thẻ không tự chuyển về COD.
- API phương thức thanh toán trả metadata thẻ đã che số, hạn thẻ, lý do không thể dùng và tình trạng gateway. Checkout có radio phương thức, danh sách thẻ, chọn một thẻ khi dịch vụ khả dụng, liên kết đến quản lý thẻ và xử lý lỗi/tải lại.
- Chưa có gateway thật: cả API lẫn giao diện đều khóa thanh toán thẻ. Gửi trực tiếp phương thức card bị từ chối trước khi tạo đơn hoặc trừ kho. Không có giao dịch thành công giả, URL thanh toán giả hoặc lưu token từ trình duyệt tùy ý.
- Form thẻ cũ đã bỏ số thẻ/CVV. Cả `/buyer/account/payment` và `/buyer/account/credit` dùng cùng component an toàn; vẫn sửa tên hiển thị và xóa thẻ thuộc tài khoản. Thêm thẻ chỉ mở khi có tích hợp gateway hoàn chỉnh.
- Seller xác nhận → chuẩn bị → sẵn sàng bàn giao, chọn địa chỉ lấy hàng thật. Vận đơn được tạo một lần cho từng order/gian hàng; mô hình Checkout tách nhiều gian hàng thành nhiều orders vẫn được giữ.
- Admin quản lý đơn vị và nhân viên, phân công/reassign trước lấy hàng, hoặc tiếp quản nhân viên/đơn vị bị khóa trong quá trình giao với ghi chú bắt buộc.
- Nhân viên dùng tài khoản Sanctum riêng, token 8 giờ, chỉ truy cập vận đơn đang được phân công và đúng đơn vị. Mật khẩu băm; không có tài khoản/mật khẩu hardcode hoặc dữ liệu nghiệp vụ demo được thêm khi triển khai.
- Giao hàng và thu COD là hai xác nhận độc lập. Xác nhận COD cần đúng nhân viên, đơn đã giao, số tiền khớp, xác nhận đã thực thu và mã biên nhận. Mỗi payment có tối đa một bản ghi thu COD.
- Giao thất bại bắt buộc lý do; hỗ trợ giao lại và hoàn hàng. Chỉ hoàn tồn kho khi đã hoàn thực tế; không khôi phục hai lần. Hoàn một đơn đã thu tiền giữ lịch sử paid, không tự giả lập hoàn tiền.
- Khóa hàng dữ liệu theo thứ tự order → shipment; mỗi thay đổi vận đơn yêu cầu UUID và `expected_version`. Gửi lại cùng nội dung không lặp nghiệp vụ; nội dung khác hoặc phiên bản cũ bị chặn. Lịch sử lưu vai trò, người thực hiện, thời điểm và ghi chú.
- Customer/Seller/Admin/Delivery cùng đọc một vận đơn. Trang chi tiết khách, trang Seller và khu vận chuyển tự làm mới mỗi 30 giây khi cần; có nút làm mới và timeline. Không thêm hạ tầng realtime.
- Giao diện mới dùng Arial/Helvetica/sans-serif vốn có ở Checkout/tài khoản, nút/input kế thừa font; tái dùng `CSystemBrand`, class `account-*`, tiện ích tiền/ngày/lỗi và Axios. Không tải font mới.

## Các trang

| Vai trò | Đường dẫn |
|---|---|
| Nhân viên | `/delivery/login`, `/delivery/dashboard`, `/delivery/orders`, `/delivery/orders/:id`, `/delivery/history` |
| Quản trị | `/admin/shipping/shipments`, `/admin/shipping/shipments/:id`, `/admin/shipping/providers`, `/admin/shipping/staff` |
| Người bán | `/seller/orders/all` |
| Khách | `/buyer/checkout`, `/buyer/account/payment`, `/buyer/account/credit`, `/buyer/account/orders`, `/buyer/order/:order` |

## API

Tất cả đường dẫn dưới tiền tố `/api/v2`.

| Phương thức | Đường dẫn | Quyền / mục đích |
|---|---|---|
| GET | `/user/payment-methods` | Khách đang đăng nhập; metadata và trạng thái khả dụng |
| POST | `/user/payment-methods/setup` | Khởi tạo qua adapter; hiện trả 503 do chưa tích hợp |
| GET/POST/PUT/DELETE | `/user/account/credit-cards...` | Danh sách an toàn, cấm PAN/CVV, chỉ sửa/xóa thẻ sở hữu; POST hiện bị khóa |
| POST | `/user/orders` | Kiểm tra lại phương thức, idempotency, giá/kho/địa chỉ ở server |
| POST | `/shop/orders/:order/confirm`, `/prepare`, `/ready` | Người bán sở hữu; ready cần `pickup_address_id` |
| PUT | `/shop/orders/:order/status` | Giữ route cũ, chỉ cho xác nhận hoặc hủy; cấm giao/hoàn |
| POST | `/delivery/auth/sign-in` | Tài khoản vận chuyển; giới hạn 5 lần/phút |
| POST | `/delivery/auth/sign-out` | Thu hồi token hiện tại |
| GET | `/delivery/me`, `/dashboard`, `/history` | Nhân viên còn hoạt động và đơn vị còn hoạt động |
| GET | `/delivery/shipments`, `/delivery/shipments/:id` | Chỉ vận đơn được phân công |
| POST | `/delivery/shipments/:id/status` | Chuyển trạng thái đúng bước |
| POST | `/delivery/shipments/:id/collect-cod` | Xác nhận COD riêng |
| GET/POST/PUT | `/admin/shipping/providers...`, `/admin/shipping/staff...` | Quản trị đơn vị/nhân viên |
| GET | `/admin/shipments`, `/admin/shipments/dashboard`, `/admin/shipments/:id` | Toàn bộ vận đơn |
| POST | `/admin/shipments/:id/assign`, `/request-return` | Phân công và xử lý sự cố; Admin không được giả xác nhận giao hàng |

Payload cập nhật vận đơn có `request_key` UUID và `expected_version`. Cập nhật trạng thái thêm `status`, giao thất bại thêm `failed_reason`. Thu COD thêm `amount`, `receipt_reference`, `cash_received: true`. Khi mất phản hồi, giao diện gửi lại đúng yêu cầu cũ.

### Tiếp quản COD tồn đọng

API trình bày vận đơn có `can_assign`; frontend dùng cờ này để hiển thị form phân công. Ngoài nhánh đang vận chuyển, admin có thể phân công lại vận đơn `DELIVERED` khi COD còn `pending` và nhân viên/đơn vị cũ đã ngừng hoạt động hoặc chưa có nhân viên. Dùng endpoint `/admin/shipments/:id/assign` với `shipper_id`, `note` bắt buộc, `request_key`, `expected_version`.

Thao tác này giữ nguyên `DELIVERED`, `delivered_at`, tồn kho và trạng thái thanh toán. Lịch sử lưu admin thực hiện, nhân viên trước và lý do. Nhân viên mới dùng endpoint thu COD hiện có, cần đúng số tiền, `cash_received: true` và biên nhận. Khoản COD đã `paid` không được phân công lại theo nhánh tiếp quản này.

## Luồng trạng thái

`READY_FOR_PICKUP → ASSIGNED → PICKED_UP → IN_TRANSIT → OUT_FOR_DELIVERY → DELIVERED`

`OUT_FOR_DELIVERY → DELIVERY_FAILED → OUT_FOR_DELIVERY` để giao lại, hoặc `DELIVERY_FAILED → RETURNING → RETURNED`. Admin có thể yêu cầu hoàn một đơn đã giao khi có lý do; nhân viên được phân công xác nhận hoàn. `CANCELLED` là trạng thái cuối; hủy trực tiếp bị chặn sau khi đã lấy hàng. `PENDING` được giữ để tương thích mô hình, còn vận đơn mới chỉ tạo khi người bán sẵn sàng.

## Database / triển khai

Migrations: `database/migrations/2026_11_10_000002_secure_payments_and_delivery.php` và `2026_11_10_000003_allow_gateway_card_metadata_only.php`. Migration thứ hai cho phép các trường billing/hạn thẻ cũ để trống, để dữ liệu gateway chỉ cần metadata cần thiết.

- Bổ sung `shipping_providers`, `delivery_staff`, `shipment_histories`, `cod_collections`.
- Bổ sung `orders.seller_status`; thêm provider/shipper/assigned_at/failed_reason/version cho bảng `shipments` sẵn có, không tạo bảng vận đơn trùng.
- Dùng lại `payments`, `credit_cards`, `checkout_receipts`, `order_items`, `order_progresses`, tồn kho hiện có.
- Thẻ bổ sung provider, token reference được mã hóa và ẩn khỏi JSON, brand, last_four, expiry month/year, trạng thái. Dữ liệu cũ được giữ tên/hạn/last4, còn PAN/CVV được thay bằng NULL. Rollback không khôi phục thông tin nhạy cảm đã loại bỏ.
- Chuẩn hóa trạng thái shipment cũ và ghi một sự kiện tiếp nhận trạng thái hiện tại, không bịa lịch sử giao/thu tiền trong quá khứ. Trạng thái thanh toán tài chính cũ được giữ để đối soát riêng, không tự xác minh lại.
- Vận đơn đang đi từ hệ thống cũ mà chưa có nhân viên có thể được Admin phân công tiếp quản kèm ghi chú. Vận đơn đã kết thúc không được mở lại để giả lập giao/thu tiền.
- Loại phần seed thẻ giả/PAN/CVV khỏi `ProjectRequirementSeeder`; không cần chạy seeder để bật tính năng này.

Trong môi trường cần triển khai, chạy `php artisan migrate --force` rồi build frontend. Migration đã được chạy trên MySQL cục bộ trong phiên làm việc này. Không triển khai hoặc sửa cấu hình production.

## Cấp tài khoản vận chuyển

1. Đăng nhập quản trị hiện có, mở `/admin/shipping/providers` và thêm tên/mã đơn vị thực tế, để hoạt động.
2. Mở `/admin/shipping/staff`, thêm nhân viên vào đơn vị đó: tên, tài khoản, email, điện thoại và mật khẩu ít nhất 12 ký tự, nhập lại mật khẩu. Không dùng thông tin tài khoản người bán.
3. Nhân viên mở `/delivery/login`, đăng nhập bằng username/email và mật khẩu vừa cấp.
4. Seller xác nhận, chuẩn bị và bàn giao đơn. Admin mở `/admin/shipping/shipments`, chọn vận đơn rồi phân công nhân viên.
5. Nhân viên xử lý tuần tự trong trang chi tiết. Sau khi giao thành công và thực thu COD, xác nhận riêng bằng mã biên nhận.

Chưa tự tạo tài khoản thật khi chưa có thông tin đơn vị/nhân viên thực tế. Các tài khoản/vận đơn trong bộ test được tạo trong transaction rồi rollback.

## Cấu hình gateway còn thiếu

Hiện chỉ có `PaymentGateway` và `UnavailableGateway`. Không có thông số môi trường nào cho phép bật thẻ một cách giả lập. Để mở thanh toán thẻ thật cần:

1. Chọn nhà cung cấp hỗ trợ quốc gia, loại thẻ và **đơn vị tiền của dữ liệu hiện tại**; dữ liệu catalog đang dùng USD. Không đổi nhãn thành VND mà không chuyển đổi dữ liệu.
2. Cài adapter/server SDK chính thức và hosted fields/hosted checkout. Dữ liệu PAN/CVV phải đi trực tiếp tới nhà cung cấp.
3. Xác thực token thanh toán phía server, kiểm tra customer/provider ownership, lấy metadata và ghi token reference được mã hóa. Thẻ cũ không phải token gateway và phải liên kết lại.
4. Hoàn thiện tạo payment intent với khóa idempotency, webhook kiểm tra chữ ký và số tiền/tiền tệ/order, xử lý lặp callback, thất bại, timeout và hoàn tiền. Chỉ cập nhật paid sau xác nhận đáng tin cậy.
5. Bổ sung kiểm thử sandbox thành công/từ chối/3DS/mất kết nối, sau đó mới mở `card_available`, `usable` và nhánh tạo thanh toán thẻ trong `PaymentMethodService`. Code hiện fail closed ngay cả khi chỉ thêm adapter setup mà chưa có charge/webhook.

Không dùng frontend để tự đánh dấu thanh toán thành công hoặc gửi payment token tùy ý vào endpoint quản lý thẻ.

## Kiểm thử thực tế

- Frontend `npm run build`: thành công; PWA tạo 171 entries. Còn cảnh báo kích thước chunk lớn và hai tên component trùng có sẵn; không làm build thất bại.
- ESLint trên toàn bộ tệp giao diện/API mới và các tệp frontend đã chỉnh trong yêu cầu này: đạt, không lỗi/cảnh báo lint.
- `php -l` kiểm tra 41 tệp PHP thay đổi/được thêm trong workspace: không lỗi cú pháp; `git diff --check` đạt ở cả hai repo.
- Sau kiểm thử, MySQL không còn đơn vị/nhân viên vận chuyển thử nghiệm (0/0); không tạo tài khoản thật hoặc mật khẩu mặc định.

- Toàn bộ backend: `RUN_MYSQL_CHECKOUT_TESTS=1 php artisan test`: **18 passed, 300 assertions**.
- Có kiểm tra giỏ hàng nhiều gian hàng được tách orders/payments/shipments và cô lập quyền mỗi Seller; COD không cần thẻ, metadata/mã thẻ an toàn, thẻ hết hạn, thẻ tài khoản khác, gateway chưa cấu hình, cùng UUID không tạo đơn trùng, Seller không giao, đúng nhân viên/đơn vị, bỏ bước, lý do thất bại, giao lại, hoàn trước/sau thu tiền, hủy sau lấy hàng bị chặn, vận đơn trùng, thu COD sai tiền/lặp, tiếp quản nhân viên khóa, API Customer/Seller cùng trạng thái.
- Xung đột hai yêu cầu dùng cùng phiên bản được kiểm tra: yêu cầu đến sau bị 409 và không thêm lịch sử/tồn kho sai. Chưa thực hiện load test nhiều process đồng thời.
- Migrations đã chạy; foreign keys được thực thi trong các ca tích hợp MySQL. Giữ lại kiểm thử giỏ hàng, duyệt Seller, tài khoản và voucher.
- Gateway thật/3DS/webhook chưa thể kiểm thử vì chưa có nhà cung cấp và credentials; không tuyên bố thanh toán thẻ đã hoạt động.
- Trình duyệt đã kiểm tra trang đăng nhập vận chuyển, lỗi thông tin sai và điều hướng khách chưa đăng nhập; kiểm tra responsive/font. Chưa đăng nhập giao diện bằng tài khoản vận chuyển thật vì chưa được cấp.

## Tệp liên quan

Backend:

```text
app/Contracts/PaymentGateway.php
app/Enums/ShipmentStatus.php
app/Http/Controllers/Api/Concerns/ValidatesOperationalInput.php
app/Http/Controllers/Api/{DeliveryAuthController,DeliveryManagementController,PaymentMethodController,ShipmentController}.php
app/Http/Controllers/Api/Account/CreditCardController.php
app/Http/Controllers/Api/Order/{OrderController,ProgressController}.php
app/Http/Middleware/RequireDeliveryStaff.php
app/Http/Requests/Account/CreditCard/{CreateCreditCardRequest,UpdateCreditCardRequest}.php
app/Http/Requests/Order/CreateOrderRequest.php
app/Http/Requests/Order/Progress/{ReadyOrderRequest,UpdateProgressRequest}.php
app/Models/CreditCard.php
app/Models/Delivery/{DeliveryStaff,ShippingProvider,ShipmentHistory}.php
app/Models/Order/{Order,Shipment}.php
app/Providers/AppServiceProvider.php
app/Services/{OrderService,OrderWorkflowService,SellerFulfillmentService,ShipmentService}.php
app/Services/Payments/{PaymentMethodService,UnavailableGateway}.php
database/migrations/2026_11_10_000002_secure_payments_and_delivery.php
database/migrations/2026_11_10_000003_allow_gateway_card_metadata_only.php
database/seeders/ProjectRequirementSeeder.php
routes/api.php
tests/Feature/{CheckoutMysqlTest,DeliveryPaymentMysqlTest}.php
```

Frontend (workspace `e-shopee`):

```text
src/components/buyer/{CAccountMethods,CPaymentMethods,CPaymentSelector}.vue
src/components/delivery/{CShipmentBoard,CShipmentDetails,CShipmentTimeline}.vue
src/layouts/delivery/{LDelivery,LDeliveryLogin}.vue
src/layouts/admin/LADashboard.vue
src/pages/delivery/{login,dashboard,history}.vue
src/pages/delivery/orders/{index,[id]}.vue
src/pages/admin/shipping/{providers,staff}.vue
src/pages/admin/shipping/shipments/{index,[id]}.vue
src/pages/admin/sellers.vue
src/pages/buyer/checkout.vue
src/pages/buyer/account/{payment,credit,orders}.vue
src/pages/buyer/order/[order].vue
src/pages/seller/orders/all.vue
src/services/{axios-instance,order-request,payment-request,delivery-request}.js
src/utils/delivery.js
src/styles/delivery.css
src/components.d.ts (tự sinh)
```
