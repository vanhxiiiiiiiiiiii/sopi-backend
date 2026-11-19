# Báo cáo sửa luồng sản phẩm e-shopee

## Nguyên nhân và thay đổi

| Luồng | Nguyên nhân | Thay đổi |
|---|---|---|
| Trang chủ và ảnh | URL ảnh trỏ đến domain cũ và thẻ tự thêm hậu tố thumbnail. Nhiều hàng trong MySQL có mảng ảnh rỗng. Trang giới thiệu chưa tải danh sách sản phẩm. | Lấy ảnh theo ID từ Laravel, giữ URL ảnh tuyệt đối hợp lệ và hiển thị SVG dự phòng khi thiếu hoặc tải lỗi. Trang chủ lấy danh sách từ API. |
| Danh sách và tìm kiếm | Trang tìm kiếm dùng danh mục cố định, bộ lọc mẫu và phép JOIN bảng nối có thể trùng hàng. API công khai chưa nhất quán về trạng thái xuất bản. | Dùng danh mục từ API, lọc qua quan hệ và trả paginator với ID gốc. Chỉ công khai sản phẩm đã đăng. |
| Thẻ và chi tiết | Chi tiết đọc ID từ Pinia và chuyển khách chưa đăng nhập sang trang login, làm mất ngữ cảnh khi mở URL trực tiếp. Một số số liệu chi tiết là dữ liệu mẫu. | Thẻ dùng RouterLink. Chi tiết đọc ID từ URL và dùng sản phẩm, biến thể, cửa hàng, danh mục, tồn kho và đánh giá từ API. Thao tác giỏ hàng và đặt hàng vẫn yêu cầu đăng nhập. |
| Điều hướng | Vue Router sinh route từ file nhưng chưa có file cho `/buyer/filter`. Một số menu trỏ tới trang chưa tồn tại. | Đăng ký trang lọc, dùng chung component với `/search/:keyword`, nối các liên kết danh mục, cửa hàng và yêu thích với route phù hợp. Bản SPA giữ trang 404 cho URL không hợp lệ. |
| Quản lý người bán | Bảng và bộ đếm hardcode, bộ lọc chưa gọi API và biểu mẫu tạo chưa gửi dữ liệu hợp lệ. | Bảng và tổng lấy từ cùng paginator của cửa hàng. Hỗ trợ tìm tên/mã/SKU, lọc, phân trang, tạo một biến thể mặc định, sửa thông tin và giá theo biến thể, ẩn, đăng lại và xóa mềm. |
| Kết nối và build | Axios khởi tạo store trước Pinia, proxy trỏ domain cũ. Workbox thiếu khai báo trực tiếp và ViteSSG 0.17 tạo đường dẫn module lỗi trên Windows. | Đọc store khi xử lý lỗi, dùng proxy API và ảnh đến Laravel. Khai báo Workbox/WindiCSS theo phiên bản đã có trong lockfile và dùng Vite build SPA. |

## File thay đổi

Backend tại `C:\Users\vietanh\Desktop\e-commerce-api`:

- API: `routes/api.php`, `app/Http/Controllers/Api/Product/ProductController.php`.
- Validation: `app/Http/Requests/Product/{Get,Search,Create,Update}ProductRequest.php`, `app/Rules/Product/{ValidProductAttributesRule,ValidProductBrandRule}.php`.
- Mô hình và dịch vụ: `app/Models/Product/Product.php`, `app/Services/{ProductService,ProductCategoryService,ResourceService}.php`.

Frontend tại `C:\Users\vietanh\Desktop\e-shopee`:

- Danh sách: `src/components/{CProductCatalog,CProductCardGrid,CProductCardFlow,CGetCategoryChildren}.vue`, `src/components/buyer/CBFeature.vue`, `src/components/head/CBSearch.vue`, `src/pages/index.vue`, `src/pages/buyer/{home,filter}.vue`, `src/pages/search/[keyword].vue`.
- Chi tiết và ảnh: `src/pages/product/[product].vue`, `src/pages/shop/[shop].vue`, `src/utils/resources.ts`, `public/img/product/placeholder.svg`.
- Người bán: `src/pages/seller/product/{all,new,category}.vue`, `src/pages/seller/shop/preview.vue`, `src/pages/seller/register.vue`, `src/components/seller/SLMenu.vue`.
- Header và yêu thích: `src/components/header/{LMenu,RMenu}.vue`, `src/components/head/{CBBrand,CBList}.vue`, `src/pages/buyer/favourite.vue`.
- Kết nối và cấu hình: `src/services/{axios-instance,product-request,resource-request}.js`, `src/{main.ts,shims.d.ts}`, `vite.config.ts`, `tsconfig.json`, `.env.production`, `index.html`, `package.json`, `pnpm-lock.yaml`.

## API và kiểm chứng

- `GET /api/v2/products/feature`, `GET /api/v2/products/search` và `GET /api/v2/products/{id}` trả dữ liệu từ MySQL. CORS cho `http://localhost:3333` trả đúng header. ID không tồn tại trả 404.
- `GET /api/v2/shop/products` hỗ trợ `page`, `limit`, `keyword` và `status`. Tổng khớp số bản ghi của cửa hàng.
- `POST /api/v2/shop/products`, `PATCH /api/v2/shop/products/{product}` và `DELETE /api/v2/shop/products/{product}` đã được kiểm tra với dữ liệu thử rollback. Kiểm tra bao gồm tìm theo SKU, giá sau sửa, lọc hết hàng, ẩn, đăng lại, xóa mềm và trả 403 khi cửa hàng khác thử sửa.
- Trình duyệt đã kiểm tra trang chủ, `/buyer/filter` với 500 sản phẩm, tìm “novita” được 4 kết quả, nhấn vào sản phẩm 33, tải lại chi tiết, ảnh thật, ảnh dự phòng, trạng thái không tìm thấy, route giỏ hàng, bảng người bán có 95 sản phẩm và mở form sửa từ một dòng tìm theo SKU. Production preview cũng mở trực tiếp chi tiết và tải được ảnh qua proxy.
- `php artisan test --no-ansi` qua 4 test và 20 assertion. `php -l` qua 12 file PHP đã sửa. `pnpm build` thành công. `pnpm exec eslint` trên các file frontend đã chỉnh sửa có 0 lỗi, còn một cảnh báo do hộp thoại xác nhận xóa.

## Phần còn lại

- MySQL có 467 sản phẩm không gắn ảnh. Các sản phẩm này dùng ảnh dự phòng cho tới khi có ảnh thật.
- Schema có trạng thái đăng, ẩn và xóa. Hết hàng được tính từ tồn kho. Tab Vi phạm chưa có trạng thái hoặc quy trình kiểm duyệt tương ứng.
- `pnpm typecheck` vẫn lỗi ở nhiều component cũ, chủ yếu do bộ kiểm tra Vue cũ xử lý thuộc tính SVG/JSX và các biến còn thiếu.
- Luồng tạo sản phẩm đã được xác nhận qua API bằng một ID ảnh hợp lệ có sẵn. Kiểm tra tải lên một ảnh mới chưa chạy vì cơ chế duyệt tự động từ chối thực thi kịch bản PHP tạm với quyền ngoài sandbox.
- Khi triển khai `dist` trên web server khác, cấu hình fallback SPA về `index.html` cho route frontend và chuyển tiếp `/api/v2`, `/resources/images` đến Laravel. Vite preview đã có cấu hình proxy local.

Không cần migration hay seed lại cho đợt sửa này.
