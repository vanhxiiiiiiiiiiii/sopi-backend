# Phí vận chuyển qua kho PTIT Hà Đông

## Thiết lập và sử dụng

- Chạy `php artisan migrate` sau khi cập nhật backend.
- Super Admin hoặc Admin vận chuyển mở frontend `/admin/shipping/rates` để chỉnh kho và biểu phí.
- Kho khởi tạo: 96A Trần Phú, Hà Đông, Hà Nội; tọa độ OSM 20.9809427, 105.7870861. Admin có thể kéo ghim tới cổng/khu vực xe lấy hàng thực tế.
- Người mua chọn địa chỉ đã có tọa độ, chọn một kết quả tìm kiếm, nhấn trên bản đồ hoặc kéo ghim: tuyến đường, phí và tổng tiền tự cập nhật. Không có bước bấm tính lại. Địa chỉ chưa có tọa độ cần chọn một vị trí; có thể xem phí ngay khi đang nhập địa chỉ mới, trước khi lưu địa chỉ.
- Form địa chỉ dùng số nhà/đường, phường/xã và tỉnh/thành phố. Cột `addresses.city` giữ dữ liệu quận/huyện lịch sử, nay cho phép NULL; tạo đơn không yêu cầu cột này. Giao diện và chuỗi tìm kiếm bỏ cấp quận/huyện.
- Backend lấy tuyến ô tô từ OSRM và tính phí từ dữ liệu sản phẩm trong database. Không sử dụng số km, cân nặng, phí do client tự gửi.

## Công thức và đơn vị

Với từng shop, cộng `product.weight × quantity` cho những mặt hàng được chọn. Cân nặng sản phẩm hiện lưu bằng **gram**, đơn giá giữ cùng đơn vị tiền với catalogue (**USD** theo dữ liệu hiện tại). Không tự quy đổi sang VND.

```text
extra_kg = max(0, ceil((total_weight_grams - included_weight_grams) / 1000))
distance_km = ceil(OSRM_distance_meters / 100) / 10
weight_fee = extra_kg × extra_kg_fee
distance_fee = round(max(0, distance_km - included_distance_km) × per_km_fee, 2)
shipping_fee = round(base_fee + weight_fee + distance_fee, 2)
payment_total = subtotal + shipping_fee - voucher_discount
```

Ngưỡng miễn phí tính trên tiền hàng **trước voucher**, theo **từng shop**. Đạt ngưỡng thì miễn toàn bộ phí. Đơn vẫn phải có tuyến đường hợp lệ và nằm trong giới hạn giao hàng. Để trống ngưỡng trong trang quản trị để tắt miễn phí.

Biểu phí mẫu: cơ bản 2 USD, gồm 1.000 gram và 3 km; mỗi kg vượt mức 1 USD; mỗi km vượt mức 0,10 USD; miễn phí từ 100 USD/shop; giới hạn 1.500 km. Ví dụ kiện 2.100 gram, tuyến 10 km, tiền hàng 30 USD: phí = 2 + 2 × 1 + 7 × 0,10 = **4,70 USD**, tổng COD **34,70 USD**.

Biểu phí tính chặng **kho phân loại → người nhận**, chưa thu riêng chặng shop → kho. OSRM trả tuyến ô tô đề xuất theo dữ liệu bản đồ, không phải theo dõi quãng đường shipper đã đi. Ghim do người dùng xác nhận phải đúng vị trí giao hàng; hệ thống không chứng thực địa chỉ văn bản và ghim là cùng một địa điểm.

## Database và bảo toàn nghiệp vụ

- `shipping_rate_settings`: một cấu hình trung tâm (`id=1`) gồm kho, tọa độ, mức phí, ngưỡng miễn phí, giới hạn km và `version` chống ghi đè cấu hình đồng thời.
- `addresses.latitude/longitude`: vị trí nhận hàng được chọn. Báo giá dùng tọa độ tạm, không sửa địa chỉ lưu; khi đặt hàng thành công mới lưu điểm đã chọn và chụp vào đơn. Sửa địa chỉ văn bản làm xóa vị trí cũ nếu không gửi vị trí mới.
- `orders.shipping_snapshot`: bản chốt nguồn tuyến đường, km, cân nặng, thành phần phí, tọa độ kho/nhận và phiên bản biểu phí tại lúc tạo đơn. Đơn cũ giữ nguyên phí.
- `orders.shipping_fee` → `shipments.shipping_fee`; `payments.amount` và COD bao gồm phí. Phí hoa hồng sàn chỉ tính trên tiền hàng sau voucher, không tính phần vận chuyển.
- Checkout có `expected_payment_total`; nếu giá/phí thay đổi kể từ lần báo giá, trả 422 để khách kiểm tra lại, trước khi trừ kho/tạo đơn. Retry cùng `request_key` trả đơn gốc và không tính lại phí.
- Lưu cấu hình tạo `admin_activity_logs` với trước/sau. Admin mua bán không được sửa biểu phí.

## Tích hợp bản đồ

- Leaflet hiển thị OSM và tuyến đường. Tìm địa chỉ chỉ chạy khi bấm nút, không dùng autocomplete.
- Backend proxy Nominatim, nhận diện bằng User-Agent, cache kết quả 7 ngày; giới hạn toàn ứng dụng một yêu cầu mới mỗi 2 giây. Không gửi tên người nhận hoặc điện thoại tới dịch vụ tìm kiếm.
- Nếu Nominatim không kết nối được hoặc trả lỗi, dùng Photon dự phòng và chỉ nhận kết quả Việt Nam; vẫn giữ giới hạn và cache chung. Đổi hoặc tắt bằng `SHIPPING_GEOCODING_FALLBACK_URL`.
- OSRM route cache 24 giờ theo URL dịch vụ và tọa độ kho/nhận; chặn điểm cách đường ô tô quá 1.000 m. Hỏng dịch vụ/không có tuyến thì chặn checkout, không thay bằng đường chim bay hoặc phí 0.
- PHP Windows dùng kho chứng chỉ hệ điều hành (`CURLSSLOPT_NATIVE_CA`), vẫn kiểm tra chứng chỉ TLS. Linux dùng CA của hệ điều hành như mặc định.
- `.env` có thể đổi `SHIPPING_ROUTING_URL`, `SHIPPING_GEOCODING_URL`, `SHIPPING_MAP_USER_AGENT`, `SHIPPING_CACHE_STORE`. Frontend có `VITE_MAP_TILE_URL`. Dùng cùng cache trung tâm giữa các instance để giữ giới hạn tìm kiếm toàn ứng dụng.
- Các endpoint công cộng phù hợp cho bài tập có lưu lượng nhỏ. Khi triển khai nhiều người dùng, dùng máy chủ routing/geocoding riêng hoặc nhà cung cấp có mức dịch vụ phù hợp; không cần thay công thức tính phí.

API: `GET/PUT /api/v2/admin/shipping/rates`, `POST /api/v2/admin/shipping/search`, `GET /api/v2/user/shipping/settings`, `POST /api/v2/user/shipping/search`, `POST /api/v2/user/orders/quote`.

Báo giá nhận `address_id` và/hoặc `delivery_location: {latitude, longitude}`. Nếu gửi ID thì phải là địa chỉ của người mua. Tọa độ dùng để lấy tuyến đường, backend vẫn tự tính km và phí. Lúc tạo đơn cần ID địa chỉ cùng `delivery_location` đang xem và `expected_payment_total`. Khóa idempotency bao gồm tọa độ, không cho dùng lại cùng request key với điểm nhận khác.

Frontend hủy request báo giá cũ và kiểm tra thứ tự phản hồi khi chọn liên tục, để giá cũ không ghi đè vị trí mới. Trong lúc tính, tổng tiền cũ được xóa và nút đặt hàng bị khóa. Kiểm thử thứ tự phản hồi: `node --test test/shipping-quote.test.mjs` trong frontend.

## Tài liệu dịch vụ

- [Địa chỉ PTIT](https://tel.ptit.edu.vn/lien-he/)
- [OSRM Route API](https://project-osrm.org/docs/v5.24.0/api/#route-service)
- [Nominatim usage policy](https://operations.osmfoundation.org/policies/nominatim/)
- [Photon API và giới hạn sử dụng](https://github.com/komoot/photon)
- [OpenStreetMap tile policy](https://operations.osmfoundation.org/policies/tiles/)
- [cURL native CA](https://curl.se/libcurl/c/CURLOPT_SSL_OPTIONS.html)

Kiểm thử: `RUN_MYSQL_CHECKOUT_TESTS=1 php vendor/phpunit/phpunit/phpunit`; kiểm thử route dùng HTTP fake, kiểm tra kết nối thật riêng trước khi nghiệm thu.
