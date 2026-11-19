# Sửa lỗi thêm giỏ hàng trên Render / TiDB

## Nguyên nhân

API `POST /api/v2/user/cart` trả HTTP 500 với SQLSTATE 1364:
`Field 'shop_id' doesn't have a default value`. Câu INSERT cũ chỉ gửi
`product_model_id`, `quantity`, `user_id`, `cart_id`; nó dựa vào trigger
`autofill_in_shop_and_product_id_in_cart_items` để điền `shop_id`, `product_id`.
TiDB không hỗ trợ trigger: https://docs.pingcap.com/tidbcloud/mysql-compatibility/.

## Thay đổi

- `CartService` ghi đủ hai khóa lấy từ model sản phẩm đã kiểm tra. API vẫn chỉ
  nhận mã phân loại và số lượng; client không được tự chọn chủ giỏ, shop hoặc sản phẩm.
- Giữ các cột bắt buộc và ràng buộc hiện có. Bản sửa này không cần migration hay seed.
- Handler chuyển lỗi xác thực và kiểm tra dữ liệu về Laravel xử lý đúng HTTP
  401/400/422; ngoại lệ bất ngờ vẫn được che chi tiết ở production và ghi log.
- Hai nút Thêm vào giỏ / Đặt hàng ngay dùng chung API này nên không cần đổi UI.

## Triển khai và xác minh

Deploy commit mới của backend trên Render (`sopi-backend`). Sau khi deploy Live,
đăng nhập tài khoản thử, mở sản phẩm và thêm vào giỏ. Kiểm tra cập nhật số lượng,
đọc giỏ và xóa mục thử. Nút Đặt hàng ngay phải chuyển sang trang giỏ sau khi thêm.
Không cần đặt đơn thật để kiểm tra lỗi này.

Kiểm thử MySQL local sử dụng bảng `cart_items` tạm không có trigger để tái hiện
đúng SQLSTATE trước khi sửa; bảng và trigger thật không bị thay đổi. Bài kiểm thử
bao gồm thêm/cập nhật/đọc/xóa, bỏ qua các khóa giả từ client, không trừ tồn kho và
không tạo đơn hàng. Các fixture được rollback sau khi kiểm tra.
