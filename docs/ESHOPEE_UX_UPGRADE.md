# Nâng cấp trải nghiệm mua sắm e-shopee

UI/UX Pro Max được dùng để tra cứu bố cục cửa hàng, phản hồi khi gửi biểu mẫu và focus bàn phím. Frontend áp dụng nền xám nhạt, bề mặt trắng, cam đậm cho hành động chính, bán kính 12 px và bước cách 8–16 px. Lưới sản phẩm có 2, 3 hoặc 5 cột. Thanh tổng kết giỏ bám cuối trên màn hình lớn và nằm trong luồng trang trên di động.

## Tệp đã sửa

- Laravel: `app/Enums/ProductOrder.php`, `app/Http/Controllers/Api/{Order/OrderController,Product/ProductController}.php`, `app/Services/{OrderService,ProductService}.php` và `tests/Feature/CheckoutMysqlTest.php`.
- Vue: `src/styles/eshop.css`, `src/main.ts`, `src/modules/i18n.ts`, `src/utils/{catalog,checkout}.js` và `src/services/order-request.js`.
- Trang và layout: `src/pages/index.vue`, `src/pages/buyer/{home,cart,checkout}.vue`, `src/pages/buyer/order/[order].vue`, `src/pages/product/[product].vue`, `src/layouts/LFilter.vue` và `src/layouts/buyer/{LBCart,LBCheckout,LBHome,LBOrder}.vue`.
- Component: `src/components/{CEshopHome,CEshopHeader,CProductCatalog,CProductCardGrid}.vue` và `src/components/buyer/{CBCategory,CBFeature}.vue`.

## Hành vi

- Danh mục dùng ID MySQL và bao gồm nhánh con. URL giữ danh mục, từ khóa, cách sắp xếp và trang. Sắp xếp bán chạy dùng trường `sold`.
- Mục gợi ý ưu tiên sản phẩm có dữ liệu ảnh. Frontend dùng ảnh gốc và ảnh dự phòng khi thiếu hoặc tải lỗi. Kiểm tra MySQL ghi nhận 467/500 sản phẩm có mảng ảnh rỗng.
- Giỏ nhóm theo cửa hàng. Checkout nhận danh sách ID biến thể được chọn. Laravel xác thực các dòng với giỏ của người mua, tính lại giá và chỉ xóa dòng đã đặt. Các dòng khác vẫn giữ nguyên.
- Trang theo dõi định dạng ngày theo tiếng Việt, dùng dấu gạch nếu dữ liệu ngày không hợp lệ và liên kết về sản phẩm đang bán.
- Giao diện mua sắm mới mặc định tiếng Việt. Tên và mô tả từ MySQL được giữ nguyên để tránh gắn nhầm nội dung.
- Dữ liệu giá chưa có trường xác nhận loại tiền. Frontend giữ cách biểu thị USD cũ và dùng định dạng `vi-VN`. `VITE_CATALOG_CURRENCY=VND` chỉ thay định dạng và chỉ nên bật sau khi xác nhận dữ liệu đã lưu bằng đồng Việt Nam.

## Kiểm thử

- PHPUnit với `RUN_MYSQL_CHECKOUT_TESTS=1`: 5 test, 64 assertion. Kiểm tra báo giá theo lựa chọn, giữ dòng còn lại, giữ tồn kho của dòng đó, chống tạo trùng và tiến trình COD. Các dữ liệu kiểm thử MySQL được rollback.
- ESLint các file thay đổi và `pnpm build`: thành công.
- Trình duyệt: mở trang chủ, chọn Điện thoại và phụ kiện, nhận 8 kết quả tại `category=72` và tải lại giữ nguyên kết quả. Mở chi tiết sản phẩm 33 và xác nhận ảnh gốc tải được. Giỏ trống hiện đúng trạng thái.

Cần bổ sung ảnh và bản dịch nội dung từ nguồn dữ liệu thật. Một số màn hình tài khoản và quản trị cũ còn văn bản tiếng Anh. Phiên này chưa kiểm thử toàn bộ quy trình đặt hàng trên trình duyệt hoặc đo ở mọi kích thước màn hình.
