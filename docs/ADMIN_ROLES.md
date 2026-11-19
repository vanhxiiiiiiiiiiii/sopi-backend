# Phân quyền quản trị: 3 vai trò

| Vai trò | Phạm vi |
| --- | --- |
| Super Admin | Tạo, sửa, khóa tài khoản admin; cấp một trong ba vai trò; xem lịch sử quản trị; truy cập toàn hệ thống |
| Admin vận chuyển | Đơn vị vận chuyển, nhân viên, vận đơn, phân công và xử lý hoàn hàng theo quy trình hiện có |
| Admin mua bán | Người mua, cửa hàng, duyệt hồ sơ người bán, kiểm duyệt sản phẩm, tra cứu/hủy đơn còn đủ điều kiện, tra cứu thanh toán và quản lý voucher |

Mỗi tài khoản admin có một vai trò, qua khóa ngoại `administrators.role_id`. Bảng `administrator_roles` có mã vai trò ổn định; quyền được xác định phía server trong `config/admin_roles.php`, không nhận danh sách quyền từ client. Người bán vẫn là `users` có `shops`, chỉ quản lý cửa hàng riêng; nhân viên giao hàng vẫn là `delivery_staff`, không phải admin vận chuyển.

## Sử dụng

- Đăng nhập tại `/admin/login`. Tài khoản Super Admin vào tổng quan; hai vai trò chuyên môn được chuyển tới khu vực của mình.
- Super Admin mở `/admin/administrators` để tạo tài khoản hoặc đổi vai trò. Mật khẩu mới tối thiểu 12 ký tự. Đổi mật khẩu, đổi vai trò hoặc khóa sẽ thu hồi token hiện tại.
- Không thể tự khóa hoặc tự đổi vai trò; phải giữ ít nhất một Super Admin hoạt động. Thay đổi quyền được khóa giao dịch và lưu lịch sử, không lưu mật khẩu vào lịch sử.
- `/admin/commerce` có các nhóm người mua, cửa hàng, sản phẩm, đơn hàng, thanh toán, voucher. Kiểm duyệt sản phẩm bắt buộc lý do; gỡ sản phẩm chỉ thay trạng thái, không xóa vật lý.
- `/admin/shipping/*` giữ nguyên nghiệp vụ vận chuyển, dùng chung header, menu, font và các class giao diện hiện có.
- Thanh toán trong khu mua bán là tra cứu. Admin không được tự giả xác nhận thu COD hoặc hoàn tiền; thu COD vẫn cần nhân viên được phân công và biên nhận.

## Triển khai và tương thích

Chạy `php artisan migrate` để áp dụng migration `2026_11_19_000001_add_admin_role_permissions`. Migration đã được áp dụng trên MySQL local trong phiên triển khai. Vai trò Administrator cũ (id 1) được giữ và đổi tên Super Admin; không thay mật khẩu hay tạo thêm tài khoản quản trị mặc định. Các vai trò chuyên môn được thêm vào để Super Admin gán khi cần.

Tất cả route admin được bảo vệ bởi xác thực, kiểm tra loại tài khoản, trạng thái hoạt động và phạm vi vai trò. Route đăng nhập/quên mật khẩu/đặt lại mật khẩu vẫn công khai. Token cũ đọc quyền hiện tại từ database; sửa localStorage hoặc gọi API trực tiếp không mở rộng được quyền. Các trang không có quyền hiển thị thông báo từ chối và không tải nội dung nghiệp vụ.

## Kiểm thử

`AdminRolesMysqlTest` kiểm tra ma trận truy cập cả ba vai trò, chặn ghi chéo phạm vi, chống truyền quyền giả từ client, thu hồi token khi đổi quyền, ngăn tự khóa và yêu cầu lý do kiểm duyệt. Các fixture nghiệp vụ trước đây được chỉ rõ là Super Admin thay vì chọn tùy ý dòng đầu tiên của bảng vai trò.

Kết quả: 38 tests, 611 assertions đạt trên MySQL. Frontend build và ESLint cho các file admin thay đổi đạt. Đã đăng nhập admin01 trên trình duyệt và kiểm tra tổng quan, phân quyền, danh sách mua bán. Không thay quyền hay mật khẩu tài khoản thật trong quá trình kiểm tra giao diện.
