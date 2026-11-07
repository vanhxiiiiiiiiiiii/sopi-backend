<?php

namespace Database\Seeders;

use App\Enums\OrderStatus;
use App\Models\Account\User\User;
use Faker\Factory as Faker;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ProjectRequirementSeeder extends Seeder
{
    private const UNIFIED_SHOP_ID = 1;

    public function run()
    {
        $faker = Faker::create('vi_VN');

        DB::transaction(function () use ($faker) {
            $this->seedCustomers($faker);
            $this->seedCarts();
            $this->seedShops($faker);
            $this->seedProducts($faker);
            $this->seedWarehouses($faker);
            $this->seedVouchers($faker);
            $this->seedOrders($faker);
            $this->seedReviews($faker);
            $this->seedFavorites();
            $this->seedPriceHistory();
            $this->seedAuxiliaryBusinessTables();
        });
    }

    private function seedCustomers($faker): void
    {
        $current = DB::table('users')->count();
        $password = Hash::make('password');
        $users = [];
        for ($i = $current + 1; $i <= 100; $i++) {
            $users[] = [
                'customer_code' => sprintf('CUS-%06d', $i),
                'national_id' => sprintf('%012d', $i),
                'username' => sprintf('customer%03d', $i),
                'email' => sprintf('customer%03d@example.com', $i),
                'password' => $password,
                'email_verified_at' => now(),
                'account_status' => $i % 25 === 0 ? 'inactive' : 'active',
                'created_at' => now()->subDays(random_int(1, 1000)),
            ];
        }
        if ($users) {
            DB::table('users')->insert($users);
        }

        DB::table('users')->orderBy('id')->get()->each(function ($user) {
            DB::table('users')->where('id', $user->id)->update([
                'customer_code' => $user->customer_code ?: sprintf('CUS-%06d', $user->id),
                'national_id' => $user->national_id ?: sprintf('%012d', $user->id),
            ]);
        });

        $profileRows = [];
        $addressRows = [];
        foreach (DB::table('users')->orderBy('id')->get() as $user) {
            if (!DB::table('user_profiles')->where('user_id', $user->id)->exists()) {
                $profileRows[] = [
                    'user_id' => $user->id,
                    'display_name' => 'Customer ' . $user->id,
                    'phone' => sprintf('09%08d', $user->id),
                    'gender' => $user->id % 2,
                    'date_of_birth' => now()->subYears(18 + ($user->id % 30))->toDateString(),
                ];
            }
            if (!DB::table('addressables')->where('addressable_id', $user->id)->where('addressable_type', User::class)->exists()) {
                $addressRows[] = $user->id;
            }
        }
        if ($profileRows) {
            DB::table('user_profiles')->insert($profileRows);
        }
        foreach ($addressRows as $userId) {
            $addressId = DB::table('addresses')->insertGetId([
                'full_name' => 'Customer ' . $userId,
                'phone' => sprintf('09%08d', $userId),
                'state' => 'Ha Noi',
                'city' => 'Ha Noi',
                'town' => 'District ' . (($userId % 12) + 1),
                'address' => ($userId + 10) . ' Sample Street',
                'is_home' => true,
            ]);
            DB::table('addressables')->insert([
                'addressable_id' => $userId,
                'addressable_type' => User::class,
                'address_id' => $addressId,
                'type_id' => 1,
            ]);
        }
    }

    private function seedShops($faker): void
    {
        $validCovers = ['demo-shop-cover-01', 'demo-shop-cover-02', 'demo-shop-cover-04', 'demo-shop-cover-05', 'demo-shop-cover-06', 'demo-shop-cover-09'];
        DB::table('shops')->orderBy('id')->get()->each(function ($shop, $index) use ($validCovers) {
            $cover = in_array($shop->cover_image, $validCovers, true)
                ? $shop->cover_image
                : $validCovers[$index % count($validCovers)];
            DB::table('shops')->where('id', $shop->id)->update([
                'seller_code' => $shop->seller_code ?: sprintf('SEL-%05d', $index + 1),
                'tax_code' => $shop->tax_code ?: sprintf('TAX-%010d', $shop->id),
                'name' => $shop->id === self::UNIFIED_SHOP_ID ? 'E-Shopee Official Mall' : $shop->name,
                'description' => $shop->id === self::UNIFIED_SHOP_ID
                    ? 'Gian hàng chính hãng E-Shopee Official Mall — Chuyên cung cấp đa dạng sản phẩm chính hãng chất lượng cao, giao hàng nhanh toàn quốc.'
                    : $shop->description,
                'phone' => $shop->phone ?: sprintf('08%08d', $shop->id),
                'email' => $shop->email ?: sprintf('seller%03d@example.com', $shop->id),
                'avatar_image' => $shop->avatar_image ?: sprintf('demo-avatar-%02d', ($index % 30) + 1),
                'cover_image' => $cover,
                'is_active' => true,
                'created_at' => $shop->created_at ?: now()->subDays(180 - ($index * 5)),
            ]);
        });

        $needed = 20 - DB::table('shops')->count();
        if ($needed <= 0) {
            return;
        }
        $userIds = DB::table('users')->whereNotIn('id', DB::table('shops')->select('id'))->limit($needed)->pluck('id');
        foreach ($userIds as $index => $userId) {
            DB::table('shops')->insert([
                'id' => $userId,
                'seller_code' => sprintf('SEL-%05d', DB::table('shops')->count() + 1),
                'tax_code' => sprintf('TAX-%010d', $userId),
                'slug' => 'shop-' . $userId,
                'name' => 'E-Shopee Mall Store ' . $userId,
                'description' => $faker->sentence(),
                'avatar_image' => sprintf('demo-avatar-%02d', ($userId % 30) + 1),
                'cover_image' => $validCovers[$index % count($validCovers)],
                'banners' => '[]',
                'phone' => sprintf('08%08d', $userId),
                'email' => sprintf('seller%03d@example.com', $userId),
                'is_active' => true,
                'created_at' => now()->subDays(random_int(1, 900)),
            ]);
        }
    }

    private function seedCarts(): void
    {
        foreach (DB::table('users')->pluck('id') as $userId) {
            $cartId = DB::table('carts')->where('user_id', $userId)->where('status', 'active')->value('id');
            if (!$cartId) {
                $cartId = DB::table('carts')->insertGetId([
                    'user_id' => $userId,
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            DB::table('cart_items')->where('user_id', $userId)->whereNull('cart_id')->update(['cart_id' => $cartId]);
        }
    }

    private function catalogTemplates(): array
    {
        return [
            // 1. Clothes
            [
                'paths' => [[1, 2], [1, 3, 4], [1, 3, 5], [1, 7], [1, 8, 9], [1, 8, 10], [1, 13], [1, 14], [1, 15, 16]],
                'images' => [
                    'https://images.unsplash.com/photo-1521572163474-6864f9cf17ab?auto=format&fit=crop&w=600&q=80',
                    'https://images.unsplash.com/photo-1556821840-3a63f95609a7?auto=format&fit=crop&w=600&q=80',
                    'https://images.unsplash.com/photo-1542272604-787c3835535d?auto=format&fit=crop&w=600&q=80',
                    'https://images.unsplash.com/photo-1591047139829-d91aecb6caea?auto=format&fit=crop&w=600&q=80',
                    'https://images.unsplash.com/photo-1542291026-7eec264c27ff?auto=format&fit=crop&w=600&q=80',
                    'https://images.unsplash.com/photo-1548036328-c9fa89d128fa?auto=format&fit=crop&w=600&q=80',
                    'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?auto=format&fit=crop&w=600&q=80',
                    'https://images.unsplash.com/photo-1543163521-1bf539c55dd2?auto=format&fit=crop&w=600&q=80',
                ],
                'items' => [
                    ['Áo Hoodie Nam Nữ Form Rộng Nỉ Bông Cao Cấp Phong Cách Hàn Quốc', 18.50, 450],
                    ['Quần Jean Nam Slimfit Co Giãn 4 Chiều Không Phai Màu', 24.00, 600],
                    ['Áo Khoác Blazer Nữ Dáng Rộng Thời Trang Công Sở Thanh Lịch', 32.00, 520],
                    ['Bộ Suit Vest Nam Công Sở Cao Cấp Thiết Kế Chuẩn Form Châu Á', 89.00, 1200],
                    ['Áo Sweater Len Dệt Kim Cổ Tròn Thu Đông Dày Dặn Mềm Mại', 19.90, 420],
                    ['Áo Cardigan Len Khoác Ngoài Nữ Phong Cách Vintage Nhẹ Nhàng', 21.50, 380],
                    ['Quần Tây Âu Nam Ống Đứng Vải Tuyết Mưa Chống Nhăn Cao Cấp', 26.00, 480],
                    ['Quần Short Kaki Nam Lưng Thun Thoáng Mát Mặc Nhà Dạo Phố', 14.50, 300],
                    ['Áo Thun Polo Nam Cổ Bẻ Vải Cá Sấu Cotton Thấm Hút Mồ Hôi', 16.90, 280],
                    ['Set Đồ Bộ Thể Thao Nam Nữ Thu Đông Năng Động Trẻ Trung', 29.50, 550],
                    ['Ba Lô Laptop Du Lịch Chống Nước Đa Năng Nhiều Ngăn Tiện Lợi', 27.90, 650],
                    ['Túi Xách Nữ Đeo Chéo Da PU Cao Cấp Thời Trang Dự Tiệc', 22.80, 410],
                    ['Giày Sneaker Nam Nữ Thể Thao Đế Êm Siêu Nhẹ Chống Trượt', 35.00, 800],
                    ['Giày Cao Gót Nữ Mũi Nhọn 5 Phân Thanh Lịch Đi Làm Dự Tiệc', 28.50, 620],
                    ['Áo Sơ Mi Trắng Dài Tay Công Sở Vải Lụa Chống Nhăn Cao Cấp', 19.00, 320],
                    ['Combo 3 Quần Lót Cotton Kháng Khuẩn Co Giãn Thoáng Khí', 12.00, 200],
                ],
            ],
            // 2. Beauty
            [
                'paths' => [[19, 20, 21], [19, 24, 25], [19, 24, 27], [19, 29, 31], [19, 33], [19, 34, 36], [19, 34, 38], [19, 34, 39], [19, 40, 41], [19, 43, 44], [19, 43, 45]],
                'images' => [
                    'https://images.unsplash.com/photo-1522335789203-aabd1fc54bc9?auto=format&fit=crop&w=600&q=80',
                    'https://images.unsplash.com/photo-1596462502278-27bfdc403348?auto=format&fit=crop&w=600&q=80',
                    'https://images.unsplash.com/photo-1571781926291-c477ebfd024b?auto=format&fit=crop&w=600&q=80',
                    'https://images.unsplash.com/photo-1556228720-195a672e8a03?auto=format&fit=crop&w=600&q=80',
                    'https://images.unsplash.com/photo-1586495777744-4413f21062fa?auto=format&fit=crop&w=600&q=80',
                    'https://images.unsplash.com/photo-1541643600914-78b084683601?auto=format&fit=crop&w=600&q=80',
                ],
                'items' => [
                    ['Sữa Rửa Mặt Dịu Nhẹ Cấp Ẩm Sâu Cho Mọi Loại Da 150ml', 14.90, 220],
                    ['Nước Hoa Hồng Cân Bằng Da Toner Hyaluronic Acid Cấp Ẩm 200ml', 18.00, 260],
                    ['Son Kem Lì Mịn Môi Lâu Trôi Chuẩn Màu Phong Cách Hàn Quốc', 11.50, 80],
                    ['Phấn Nước Cushion Kiềm Dầu Che Khuyết Điểm Chống Nắng SPF50+', 24.50, 150],
                    ['Nước Tẩy Trang Micellar Làm Sạch Sâu Dịu Nhẹ Không Cồn 400ml', 15.90, 450],
                    ['Nước Hoa Nữ Cao Cấp Hương Hoa Cỏ Tự Nhiên Lưu Hương 12 Giờ', 42.00, 300],
                    ['Dầu Gội Thảo Dược Bồ Kết Bưởi Ngăn Rụng Tóc Kích Mọc Tóc 500ml', 16.80, 580],
                    ['Kem Ủ Phục Hồi Tóc Hư Tổn Keratin Collagen Mềm Mượt 500ml', 19.50, 560],
                    ['Bộ Cọ Trang Điểm Chuyên Nghiệp 12 Cây Lông Tự Nhiên Kèm Túi', 13.90, 210],
                    ['Kem Dưỡng Da Tay Chiết Xuất Bơ Hạt Mỡ Mềm Mịn Chống Khô Nẻ', 8.90, 120],
                    ['Tinh Chất Serum Vitamin C Sáng Da Mờ Thâm Cấp Ẩm Chuyên Sâu', 26.90, 140],
                    ['Kem Chống Nắng Phổ Rộng Kiềm Dầu Nâng Tone Tự Nhiên SPF50+ PA++++', 21.00, 130],
                    ['Sữa Tắm Hương Nước Hoa Dưỡng Ẩm Trắng Da Mịn Màng 800ml', 17.50, 850],
                    ['Bộ Chăm Sóc Da Nam Giới Sữa Rửa Mặt Và Kem Dưỡng Kiểm Soát Dầu', 28.00, 380],
                ],
            ],
            // 3. Health
            [
                'paths' => [[47, 48, 49], [47, 48, 50], [47, 48, 51], [47, 52, 53], [47, 52, 54], [47, 55, 56], [47, 55, 58]],
                'images' => [
                    'https://images.unsplash.com/photo-1584308666744-24d5c474f2ae?auto=format&fit=crop&w=600&q=80',
                    'https://images.unsplash.com/photo-1517836357463-d25dfeac3438?auto=format&fit=crop&w=600&q=80',
                    'https://images.unsplash.com/photo-1592432678016-e910b452f9a2?auto=format&fit=crop&w=600&q=80',
                    'https://images.unsplash.com/photo-1576678927484-cc907957088c?auto=format&fit=crop&w=600&q=80',
                    'https://images.unsplash.com/photo-1559757175-5700dde675bc?auto=format&fit=crop&w=600&q=80',
                ],
                'items' => [
                    ['Máy Đo Huyết Áp Bắp Tay Điện Tử Tự Động Màn Hình LCD Lớn', 38.00, 450],
                    ['Cân Điện Tử Thông Minh Đo Chỉ Số Cơ Thể BMI Kết Nối Bluetooth', 25.00, 1500],
                    ['Viên Uống Bổ Sung Vitamin C 1000mg & Kẽm Tăng Cường Đề Kháng', 16.50, 250],
                    ['Thực Phẩm Bổ Sung Collagen Peptide Thủy Phân Đẹp Da Chống Lão Hóa', 34.00, 320],
                    ['Bàn Chải Đánh Răng Điện Sóng Âm Chống Nước IPX7 Kèm 4 Đầu Thay', 29.00, 350],
                    ['Máy Tăm Nước Cầm Tay Vệ Sinh Răng Miệng Chuyên Sâu 3 Chế Độ', 31.50, 420],
                    ['Dung Dịch Nhỏ Mắt Cấp Ẩm Giảm Mỏi Mắt Cho Người Dùng Máy Tính', 7.50, 90],
                    ['Thảm Tập Yoga Định Tuyến TPE 2 Lớp Dày 8mm Chống Trượt Cao Cấp', 23.00, 950],
                    ['Bột Đạm Whey Protein Isolate Hỗ Trợ Tăng Cơ Phục Hồi Sau Tập 2Lbs', 58.00, 1100],
                    ['Trà Thảo Mộc Thanh Nhiệt Hỗ Trợ Kiểm Soát Cân Nặng Tự Nhiên', 15.00, 200],
                    ['Nhiệt Kế Hồng Ngoại Đo Trán Không Tiếp Xúc Chính Xác Trong 1 Giây', 22.00, 180],
                    ['Máy Massage Cổ Vai Gáy Hồng Ngoại Xung Điện Giảm Đau Mỏi', 36.00, 520],
                ],
            ],
            // 4. Home Appliances
            [
                'paths' => [[60, 61, 62], [60, 61, 64], [60, 65, 66], [60, 65, 67], [60, 65, 68], [60, 65, 69], [60, 70], [60, 71]],
                'images' => [
                    'https://images.unsplash.com/photo-1556911220-bff31c812dba?auto=format&fit=crop&w=600&q=80',
                    'https://images.unsplash.com/photo-1517668808822-9ebb02f2a0e6?auto=format&fit=crop&w=600&q=80',
                    'https://images.unsplash.com/photo-1570222094114-d054a817e56b?auto=format&fit=crop&w=600&q=80',
                    'https://images.unsplash.com/photo-1593359677879-a4bb92f829d1?auto=format&fit=crop&w=600&q=80',
                    'https://images.unsplash.com/photo-1585515320310-259814833e62?auto=format&fit=crop&w=600&q=80',
                ],
                'items' => [
                    ['Ấm Đun Nước Siêu Tốc Inox 304 Hai Lớp Chống Bỏng 1.8 Lít', 19.50, 900],
                    ['Máy Pha Cà Phê Espresso Bán Tự Động Áp Suất 20 Bar Kèm Vòi Đánh Sữa', 129.00, 4200],
                    ['Máy Xay Sinh Tố Đa Năng Cối Thủy Tinh Chịu Lực Công Suất 800W', 45.00, 2400],
                    ['Smart TV 4K UHD 55 Inch Viền Siêu Mỏng Tìm Kiếm Giọng Nói', 389.00, 12500],
                    ['Giá Treo Tivi Xoay Đa Năng Góc Rộng Cho Màn Hình 32-65 Inch', 24.00, 2200],
                    ['Tủ Bảo Quản Rượu Vang Chuyên Dụng 28 Chai Làm Lạnh Chống Rung', 249.00, 18000],
                    ['Nồi Chiên Không Dầu Điện Tử Màn Hình Cảm Ứng Dung Tích 6.5L', 78.00, 4800],
                    ['Robot Hút Bụi Lau Nhà Thông Minh Cảm Biến Laser Tự Sạc', 215.00, 3500],
                    ['Máy Đánh Trứng Nhào Bột Cầm Tay 7 Tốc Độ Inox Không Gỉ', 21.00, 1100],
                    ['Bộ 8 Viên Pin Sạc AA Dung Lượng Cao 2800mAh Kèm Bộ Sạc Nhanh', 18.50, 300],
                    ['Điều Khiển Từ Xa Đa Năng Học Lệnh Cho Smart TV Và Điều Hòa', 9.90, 150],
                    ['Nồi Cơm Điện Tử Cao Tần IH Đa Chế Độ Nấu Giữ Nhiệt 24 Giờ 1.8L', 95.00, 3800],
                ],
            ],
            // 5. Mobile & Gadgets
            [
                'paths' => [[72, 73], [72, 74], [72, 75], [72, 76, 77], [72, 76, 78], [72, 79, 80], [72, 79, 81], [72, 79, 83], [72, 79, 84]],
                'images' => [
                    'https://images.unsplash.com/photo-1511707171634-5f897ff02aa9?auto=format&fit=crop&w=600&q=80',
                    'https://images.unsplash.com/photo-1544244015-0df4b3ffc6b0?auto=format&fit=crop&w=600&q=80',
                    'https://images.unsplash.com/photo-1523275335684-37898b6baf30?auto=format&fit=crop&w=600&q=80',
                    'https://images.unsplash.com/photo-1546868871-7041f2a55e12?auto=format&fit=crop&w=600&q=80',
                    'https://images.unsplash.com/photo-1592899677977-9c10ca588bbd?auto=format&fit=crop&w=600&q=80',
                ],
                'items' => [
                    ['Điện Thoại Thông Minh 5G Màn Hình Super AMOLED 120Hz 8GB/256GB', 329.00, 520],
                    ['Máy Tính Bảng Màn Hình 11 Inch 2K 8GB/128GB Học Tập Làm Việc', 259.00, 680],
                    ['Đồng Hồ Thông Minh Smartwatch Màn Hình AMOLED Nghe Gọi Bluetooth', 49.00, 180],
                    ['Thiết Bị Định Vị GPS Mini Thông Minh Theo Dõi Xe Máy Ô Tô Hành Lý', 28.00, 120],
                    ['Kính Thực Tế Ảo VR 3D Toàn Cảnh Kèm Tay Cầm Điều Khiển Bluetooth', 39.00, 450],
                    ['Thẻ Nhớ MicroSDXC Tốc Độ Cao 128GB UHS-I U3 Chuyên Camera Điện Thoại', 16.50, 50],
                    ['Đèn LED Livestream Để Bàn 10 Inch 3 Chế Độ Sáng Kèm Tripod', 19.90, 600],
                    ['Quạt Tản Nhiệt Điện Thoại Sò Lạnh Cổng Type-C Chơi Game Mượt Mà', 15.50, 160],
                    ['Combo 2 Kính Cường Lực Full Màn Hình Chống Nhìn Trộm Độ Cứng 9H', 8.50, 90],
                    ['SIM Data 4G/5G Quốc Tế Dung Lượng Cao Tốc Độ Cao Du Lịch Công Tác', 14.00, 40],
                    ['Pin Sạc Dự Phòng Sạc Nhanh 20000mAh PD 22.5W Tích Hợp Cáp Sạc', 27.00, 420],
                    ['Củ Sạc Nhanh GaN 65W 3 Cổng Type-C USB-A Cho Điện Thoại Laptop', 24.90, 190],
                ],
            ],
            // 6. Audio
            [
                'paths' => [[85, 86], [85, 87, 88], [85, 92], [85, 93], [85, 94, 95], [85, 94, 96]],
                'images' => [
                    'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?auto=format&fit=crop&w=600&q=80',
                    'https://images.unsplash.com/photo-1608043152269-423dbba4e7e1?auto=format&fit=crop&w=600&q=80',
                    'https://images.unsplash.com/photo-1590658268037-6bf12165a8df?auto=format&fit=crop&w=600&q=80',
                    'https://images.unsplash.com/photo-1545454675-3531b543be5d?auto=format&fit=crop&w=600&q=80',
                    'https://images.unsplash.com/photo-1583394838336-acd977736f90?auto=format&fit=crop&w=600&q=80',
                ],
                'items' => [
                    ['Tai Nghe Bluetooth True Wireless Chống Ồn Chủ Động ANC Pin 30 Giờ', 54.00, 220],
                    ['Tai Nghe Chụp Tai Không Dây Hi-Res Audio Đệm Tai Da Mềm Êm Ái', 68.00, 420],
                    ['Loa Bluetooth Di Động Chống Nước IPX7 Âm Bass Mạnh Mẽ Công Suất 30W', 46.00, 850],
                    ['Micro Thu Âm Không Dây Kép Cài Áo Lọc Ồn Cho Điện Thoại Máy Ảnh', 35.00, 240],
                    ['Hệ Thống Loa Soundbar Home Theater 2.1 Kèm Subwoofer Không Dây', 159.00, 5400],
                    ['Máy Nghe Nhạc Hi-Fi Lossless MP3/FLAC Màn Hình Cảm Ứng Bluetooth 5.0', 62.00, 280],
                    ['Amply Khuếch Đại Âm Thanh Stereo Bluetooth 5.0 Hifi Công Suất 200W', 85.00, 1800],
                    ['Bộ Giải Mã Âm Thanh AV Receiver Đa Kênh 5.1 Hỗ Trợ HDMI 4K ARC', 275.00, 6200],
                    ['Loa Bookshelf Gỗ Cổ Điển Âm Thanh Chi Tiết Cho Phòng Làm Việc', 118.00, 3800],
                    ['Micro Condenser Phòng Thu Cổng USB Kèm Chân Đế Arm Và Màng Lọc', 48.00, 950],
                ],
            ],
            // 7. Pets
            [
                'paths' => [[97, 98, 99], [97, 98, 100], [97, 98, 101], [97, 102, 103], [97, 102, 104], [97, 105, 106]],
                'images' => [
                    'https://images.unsplash.com/photo-1583337130417-3346a1be7dee?auto=format&fit=crop&w=600&q=80',
                    'https://images.unsplash.com/photo-1514888286974-6c03e2ca1dba?auto=format&fit=crop&w=600&q=80',
                    'https://images.unsplash.com/photo-1548199973-03cce0bbc87b?auto=format&fit=crop&w=600&q=80',
                    'https://images.unsplash.com/photo-1576201836106-db1758fd1c97?auto=format&fit=crop&w=600&q=80',
                ],
                'items' => [
                    ['Thức Ăn Hạt Hữu Cơ Cho Chó Trưởng Thành Vị Thịt Bò Và Rau Củ 2kg', 19.00, 2100],
                    ['Hạt Dinh Dưỡng Cho Mèo Mọi Lứa Tuổi Cá Hồi Mượt Lông Tiêu Búi Lông 1.5kg', 17.50, 1600],
                    ['Thức Ăn Ngũ Cốc Trái Cây Tổng Hợp Giàu Vitamin Cho Chim Cảnh 1kg', 9.80, 1050],
                    ['Máy Cho Thú Cưng Ăn Tự Động Hẹn Giờ Điều Khiển Qua Điện Thoại 4L', 52.00, 1400],
                    ['Đài Phun Nước Tự Động Lọc Than Hoạt Tính Siêu Êm Cho Chó Mèo 2.5L', 24.00, 750],
                    ['Bộ Dây Dắt Kèm Yếm Ngực Chống Giật Phản Quang Ban Đêm Cho Chó Mèo', 12.50, 220],
                    ['Áo Nỉ Hoodie Giữ Ấm Mùa Đông Đáng Yêu Cho Chó Mèo Thú Cưng', 10.90, 150],
                    ['Bát Ăn Đôi Inox Chống Gù Cổ Kèm Khay Chống Tràn Cho Chó Mèo', 14.50, 480],
                    ['Combo 12 Gói Pate Thưởng Cho Mèo Hương Cá Ngừ Thịt Gà Tự Nhiên', 11.90, 900],
                    ['Balo Phi Hành Gia Trong Suốt Vận Chuyển Chó Mèo Có Lỗ Thoáng Khí', 26.00, 1100],
                ],
            ],
            // 8. Gaming & Consoles
            [
                'paths' => [[109, 110, 111], [109, 110, 112], [109, 110, 113], [109, 114], [109, 115, 119]],
                'images' => [
                    'https://images.unsplash.com/photo-1606144042614-b2417e99c4e3?auto=format&fit=crop&w=600&q=80',
                    'https://images.unsplash.com/photo-1592840496694-26d035b52b48?auto=format&fit=crop&w=600&q=80',
                    'https://images.unsplash.com/photo-1600080972464-8e5f35f63d08?auto=format&fit=crop&w=600&q=80',
                    'https://images.unsplash.com/photo-1612287230202-1ff1d85d1bdf?auto=format&fit=crop&w=600&q=80',
                ],
                'items' => [
                    ['Máy Chơi Game Console 4K 120Hz Ổ Cứng 1TB SSD Kèm Tay Cầm Không Dây', 489.00, 4200],
                    ['Máy Chơi Game Cầm Tay Màn Hình OLED 7 Inch 64GB Pin Thời Lượng Cao', 329.00, 950],
                    ['Tay Cầm Chơi Game Không Dây Hall Effect Rung Phản Hồi Đa Nền Tảng', 44.00, 380],
                    ['Đế Sạc Nhanh 2 Tay Cầm Chơi Game Kèm Giá Treo Tai Nghe Và Quạt Tản Nhiệt', 28.00, 620],
                    ['Bộ Đĩa Game Hành Động Phiêu Lưu Thế Giới Mở Phiên Bản Đặc Biệt', 59.00, 180],
                    ['Vô Lăng Đua Xe Giả Lập Góc Quay 900 Độ Kèm Cần Số Và Bàn Đạp', 219.00, 5200],
                    ['Túi Chống Sốc Bảo Vệ Máy Chơi Game Cầm Tay Kèm Ngăn Đựng Thẻ Game', 15.90, 320],
                    ['Bàn Điều Khiển Arcade Fightstick Nút Bấm Cơ Học Chuyên Game Đối Kháng', 89.00, 2100],
                    ['Thẻ Mở Rộng Bộ Nhớ Tốc Độ Cao 1TB Cho Máy Chơi Game Console', 119.00, 120],
                    ['Bộ Bọc Cần Analog Silicon Chống Trượt Tăng Độ Chính Xác Khi Chơi Game', 6.90, 60],
                ],
            ],
            // 9. Stationery
            [
                'paths' => [[120, 121, 122], [120, 121, 123], [120, 121, 125], [120, 126, 127], [120, 126, 129], [120, 130, 132], [120, 130, 133]],
                'images' => [
                    'https://images.unsplash.com/photo-1456735190827-d1262f71b8a3?auto=format&fit=crop&w=600&q=80',
                    'https://images.unsplash.com/photo-1544716278-ca5e3f4abd8c?auto=format&fit=crop&w=600&q=80',
                    'https://images.unsplash.com/photo-1517842645767-c639042777db?auto=format&fit=crop&w=600&q=80',
                    'https://images.unsplash.com/photo-1583485088034-697b5bc54ccd?auto=format&fit=crop&w=600&q=80',
                ],
                'items' => [
                    ['Hộp 12 Bút Gel Nước Mực Đều Nhanh Khô Ngòi 0.5mm Viết Êm Tay', 8.90, 180],
                    ['Bộ 12 Bút Chì Gỗ Vẽ Kỹ Thuật Phác Thảo Độ Đậm 2H Đến 8B', 9.50, 160],
                    ['Set 6 Bút Dạ Quang Highlight Màu Pastel Dịu Mắt Đánh Dấu Sách Vở', 7.50, 120],
                    ['Máy Tính Khoa Học 580 Tính Năng Màn Hình Hiển Thị Tự Nhiên Cho Học Sinh', 21.00, 240],
                    ['Bộ Thước Kẻ Hình Học Hợp Kim Nhôm 4 Món Chống Gãy Mờ Vạch Chia', 6.80, 140],
                    ['Gram Giấy In Văn Phòng A4 500 Tờ Định Lượng 80gsm Trắng Mịn', 7.90, 2500],
                    ['Combo 5 Xấp Giấy Ghi Chú Sticky Notes Nhiều Màu Dán Chắc Không Để Keo', 5.90, 200],
                    ['Sổ Tay Kế Hoạch Bìa Da Cao Cấp A5 Giấy Dày 100gsm Chống Thấm Mực', 13.50, 420],
                    ['Giá Đỡ Đọc Sách Gỗ Tự Nhiên Điều Chỉnh 6 Góc Độ Chống Mỏi Cổ', 16.00, 850],
                    ['Hộp Đựng Văn Phòng Phẩm Để Bàn Đa Năng Nhiều Ngăn Gọn Gàng', 12.90, 520],
                ],
            ],
            // 10. Computers & Accessories
            [
                'paths' => [[134, 135, 136], [134, 135, 137], [134, 139], [134, 140, 142], [134, 140, 143], [134, 140, 144], [134, 146, 148], [134, 150, 151], [134, 150, 152], [134, 153]],
                'images' => [
                    'https://images.unsplash.com/photo-1496181133206-80ce9b88a853?auto=format&fit=crop&w=600&q=80',
                    'https://images.unsplash.com/photo-1527443224154-c4a3942d3acf?auto=format&fit=crop&w=600&q=80',
                    'https://images.unsplash.com/photo-1587829741301-dc798b83add3?auto=format&fit=crop&w=600&q=80',
                    'https://images.unsplash.com/photo-1527864550417-7fd91fc51a46?auto=format&fit=crop&w=600&q=80',
                    'https://images.unsplash.com/photo-1593642632823-8f785ba67e45?auto=format&fit=crop&w=600&q=80',
                ],
                'items' => [
                    ['Laptop Mỏng Nhẹ Vỏ Nhôm 14 Inch 2.8K OLED Core i7 16GB RAM 512GB SSD', 699.00, 1350],
                    ['Màn Hình Máy Tính 27 Inch 2K QHD IPS 165Hz Chuẩn Màu 100% sRGB', 229.00, 4500],
                    ['Bàn Phím Cơ Không Dây 3 Mode Bluetooth/2.4G/USB-C Hot-Swap Keycap PBT', 64.00, 920],
                    ['Chuột Không Dây Công Thái Học Silent Click DPI 4000 Sạc Nhanh Type-C', 28.00, 130],
                    ['Ổ Cứng SSD M.2 NVMe PCIe Gen4 x4 1TB Tốc Độ Đọc 7000MB/s', 79.00, 80],
                    ['Bộ Nhớ RAM PC DDR4 16GB (2x8GB) Bus 3200MHz Tản Nhiệt Hợp Kim', 46.00, 160],
                    ['Máy Tính Mini PC Văn Phòng Core i5 16GB RAM 512GB SSD WiFi 6 Nhỏ Gọn', 319.00, 1100],
                    ['Máy Tính Để Bàn Desktop PC Đồ Họa Gaming 16GB RAM SSD 1TB', 589.00, 7500],
                    ['Bộ Vi Xử Lý CPU Đa Nhân Hiệu Năng Cao Cho PC Gaming Và Đồ Họa', 199.00, 320],
                    ['Card Màn Hình Đồ Họa VGA 8GB GDDR6 Chiến Game 2K Mượt Mà', 289.00, 1200],
                    ['Giá Đỡ Laptop Hợp Kim Nhôm Xoay 360 Độ Tản Nhiệt Thoáng Khí', 23.50, 780],
                    ['Hub Chuyển Đổi Type-C 8 Trong 1 HDMI 4K LAN Gigabit PD 100W', 29.90, 150],
                ],
            ],
        ];
    }

    private function restoreOriginalCategoryPaths(): void
    {
        DB::table('product_category_paths')->where('product_id', '<=', 33)->delete();
        (new ProductCategoryPathSeeder())->run();
        DB::table('product_category_paths')->where('product_id', 33)->delete();
        DB::table('product_category_paths')->insert([
            ['product_id' => 33, 'category_id' => 60],
            ['product_id' => 33, 'category_id' => 65],
            ['product_id' => 33, 'category_id' => 66],
        ]);
    }

    private function seedProducts($faker): void
    {
        $this->restoreOriginalCategoryPaths();

        // Fix image typo, literal \n in description, and populate realistic sold/rating on products 1..33
        DB::table('products')->where('id', '<=', 33)->orderBy('id')->get()->each(function ($product) {
            $images = json_decode($product->images ?: '[]', true) ?: [];
            $images = array_map(fn ($img) => str_replace(' (1)', '', $img), $images);
            $description = str_replace('\\n', "\n", (string) $product->description);
            DB::table('products')->where('id', $product->id)->update([
                'product_code' => $product->product_code ?: sprintf('PRD-%07d', $product->id),
                'description' => $description,
                'cost_price' => max(0, round(((float) $product->avg_price) * 0.65, 2)),
                'unit' => 'item',
                'images' => json_encode($images),
                'sold' => $product->sold > 0 ? $product->sold : (($product->id * 17) % 240) + 18,
                'avg_rating' => $product->avg_rating > 0 ? $product->avg_rating : round(4.3 + (($product->id % 8) * 0.1), 1),
                'created_at' => $product->created_at ?: now()->subDays(35 - $product->id),
            ]);
        });

        $targetTotal = 800;
        $needed = $targetTotal - DB::table('products')->count();
        $rows = [];
        for ($i = 0; $i < $needed; $i++) {
            $rows[] = [
                'product_code' => null,
                'name' => 'Product ' . ($i + 1),
                'description' => '',
                'weight' => 500,
                'avg_rating' => 4.8,
                'avg_price' => 25,
                'cost_price' => 16.25,
                'unit' => 'item',
                'sold' => 25,
                'images' => '[]',
                'videos' => '[]',
                'variations' => '[]',
                'shop_id' => self::UNIFIED_SHOP_ID,
                'brand_id' => 1,
                'status_id' => 1,
                'created_at' => now()->subDays(($i % 180) + 1),
            ];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('products')->insert($chunk);
        }

        // Clear product_code on products >= 34 first to avoid any unique collision before re-numbering by actual ID
        DB::table('products')->where('id', '>=', 34)->update(['product_code' => null]);

        // Enrich all products from id >= 34 with rich catalog data, online images, and single shop (shop_id = 1)
        $groups = $this->catalogTemplates();
        $groupCount = count($groups);
        $seededProducts = DB::table('products')->where('id', '>=', 34)->orderBy('id')->get();
        $seededIds = $seededProducts->pluck('id')->all();

        if ($seededIds) {
            DB::table('product_category_paths')->whereIn('product_id', $seededIds)->delete();
        }

        $pathRows = [];
        foreach ($seededProducts as $idx => $product) {
            $groupIndex = $idx % $groupCount;
            $group = $groups[$groupIndex];
            $itemIndex = intdiv($idx, $groupCount) % count($group['items']);
            $edition = intdiv($idx, $groupCount * count($group['items'])) + 1;
            [$baseName, $basePrice, $weight] = $group['items'][$itemIndex];

            $suffixes = ['Chính Hãng', 'Cao Cấp', 'Bản Quốc Tế', 'Mẫu Mới', 'Bảo Hành 12 Tháng', 'Ưu Đãi Đặc Biệt', 'Chuẩn Mall'];
            $suffix = $suffixes[$idx % count($suffixes)];
            $name = $edition > 1
                ? sprintf('%s - %s (Mã #%d)', $baseName, $suffix, $product->id)
                : sprintf('%s - %s', $baseName, $suffix);

            $price = round($basePrice * (0.85 + (($idx % 7) * 0.05)), 2);
            $groupImages = $group['images'];
            $img1 = $groupImages[$itemIndex % count($groupImages)];
            $img2 = $groupImages[($itemIndex + 1) % count($groupImages)];
            $sold = (($product->id * 13) % 320) + 12;
            $rating = round(4.2 + (($product->id % 9) * 0.1), 1);

            $description = implode("\n", [
                "🌟 {$name}",
                "• Phân phối chính thức bởi gian hàng E-Shopee Official Mall.",
                "• Cam kết sản phẩm chính hãng 100%, kiểm tra kỹ lưỡng trước khi đóng gói.",
                "• Chất lượng hoàn thiện cao cấp, độ bền vượt trội và an toàn khi sử dụng.",
                "• Giao hàng nhanh toàn quốc, hỗ trợ đổi trả trong vòng 7 ngày nếu có lỗi từ nhà sản xuất.",
                "• Trọng lượng đóng gói: {$weight}g | Mã sản phẩm: " . sprintf('PRD-%07d', $product->id),
            ]);

            DB::table('products')->where('id', $product->id)->update([
                'product_code' => sprintf('PRD-%07d', $product->id),
                'name' => $name,
                'description' => $description,
                'weight' => $weight,
                'avg_rating' => $rating,
                'avg_price' => $price,
                'cost_price' => round($price * 0.65, 2),
                'unit' => 'item',
                'sold' => $sold,
                'images' => json_encode([$img1, $img2]),
                'variations' => json_encode([]),
                'shop_id' => self::UNIFIED_SHOP_ID,
                'brand_id' => 1,
                'status_id' => 1,
            ]);

            DB::table('product_models')->where('product_id', $product->id)->update([
                'price' => $price,
                'variation_index' => '[]',
            ]);

            $selectedPath = $group['paths'][intdiv($idx, $groupCount) % count($group['paths'])];
            foreach ($selectedPath as $catId) {
                $pathRows[] = [
                    'product_id' => $product->id,
                    'category_id' => $catId,
                ];
            }
        }

        foreach (array_chunk($pathRows, 500) as $chunk) {
            DB::table('product_category_paths')->insertOrIgnore($chunk);
        }

        $productsWithoutModel = DB::table('products as p')->leftJoin('product_models as pm', 'pm.product_id', '=', 'p.id')
            ->whereNull('pm.id')->select('p.id', 'p.avg_price')->get();
        $modelRows = [];
        foreach ($productsWithoutModel as $product) {
            $modelRows[] = [
                'product_id' => $product->id,
                'sku' => null,
                'price' => max(0.5, (float) $product->avg_price),
                'stock' => $product->id % 50 === 0 ? 0 : ($product->id % 25 === 0 ? 5 : random_int(25, 200)),
                'variation_index' => '[]',
                'is_default' => true,
            ];
        }
        foreach (array_chunk($modelRows, 200) as $chunk) {
            DB::table('product_models')->insert($chunk);
        }

        DB::table('product_models')->whereNull('sku')->orWhere('sku', '')->orderBy('id')->get()->each(function ($model) {
            DB::table('product_models')->where('id', $model->id)->update(['sku' => sprintf('SKU-%07d', $model->id)]);
        });

        // Sync order_items product_name and product_code with updated products
        DB::statement('UPDATE order_items oi JOIN products p ON p.id = oi.product_id SET oi.product_name = p.name, oi.product_code = p.product_code');

        // Sync shop_statistics.product_count
        foreach (DB::table('shops')->pluck('id') as $shopId) {
            $count = DB::table('products')->where('shop_id', $shopId)->where('status_id', 1)->count();
            DB::table('shop_statistics')->updateOrInsert(
                ['shop_id' => $shopId],
                ['product_count' => $count],
            );
        }
    }

    private function seedWarehouses($faker): void
    {
        $existingShopIds = DB::table('warehouses')->pluck('shop_id')->all();
        $shopIds = DB::table('shops')->orderBy('id')->pluck('id');
        foreach ($shopIds as $shopId) {
            if (in_array($shopId, $existingShopIds, true)) {
                continue;
            }
            DB::table('warehouses')->insert([
                'warehouse_code' => sprintf('WH-%03d', $shopId),
                'shop_id' => $shopId,
                'name' => 'Warehouse ' . $shopId,
                'address' => $faker->address(),
                'manager_user_id' => $shopId,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $warehouseByShop = DB::table('warehouses')->pluck('id', 'shop_id');
        $models = DB::table('product_models as pm')->join('products as p', 'p.id', '=', 'pm.product_id')
            ->select('pm.id', 'pm.stock', 'p.shop_id')->get();
        foreach ($models as $model) {
            if (!$warehouseByShop->has($model->shop_id)) {
                continue;
            }
            $exists = DB::table('inventory_stocks')->where('warehouse_id', $warehouseByShop[$model->shop_id])
                ->where('product_model_id', $model->id)->exists();
            DB::table('inventory_stocks')->updateOrInsert([
                'warehouse_id' => $warehouseByShop[$model->shop_id],
                'product_model_id' => $model->id,
            ], [
                'quantity' => $model->stock,
                'reserved_quantity' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if (!$exists) {
                DB::table('inventory_movements')->insert([
                    'warehouse_id' => $warehouseByShop[$model->shop_id],
                    'product_model_id' => $model->id,
                    'order_id' => null,
                    'movement_type' => 'import',
                    'quantity_change' => $model->stock,
                    'quantity_after' => $model->stock,
                    'note' => 'Initial sample inventory',
                    'created_at' => now()->subDays(30),
                ]);
            }
        }
    }

    private function seedVouchers($faker): void
    {
        $needed = 20 - DB::table('vouchers')->count();
        for ($i = 1; $i <= $needed; $i++) {
            $expired = $i > 15;
            DB::table('vouchers')->insert([
                'code' => sprintf('VOUCHER%02d', $i),
                'name' => 'Sample Voucher ' . $i,
                'discount_type' => $i % 2 === 0 ? 'percentage' : 'fixed',
                'discount_value' => $i % 2 === 0 ? 10 : 5,
                'minimum_order_value' => 20,
                'maximum_discount' => 50,
                'starts_at' => $expired ? now()->subMonths(3) : now()->subMonth(),
                'ends_at' => $expired ? now()->subMonth() : now()->addMonths(3),
                'max_uses' => 100,
                'max_uses_per_customer' => 1,
                'used_count' => 0,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function seedOrders($faker): void
    {
        $this->backfillLegacyOrders();
        $needed = 1000 - DB::table('orders')->count();
        $userIds = DB::table('users')->pluck('id')->all();
        $addresses = DB::table('addressables')->where('addressable_type', User::class)->pluck('address_id', 'addressable_id');
        $warehouseShopIds = DB::table('warehouses')->pluck('shop_id')->all();
        $modelsByShop = DB::table('product_models as pm')->join('products as p', 'p.id', '=', 'pm.product_id')
            ->whereIn('p.shop_id', $warehouseShopIds)->select('pm.id', 'pm.product_id', 'pm.price', 'pm.variation_index', 'p.shop_id', 'p.product_code', 'p.name')->get()->groupBy('shop_id');
        $shopIds = $modelsByShop->keys()->all();

        for ($i = 0; $i < $needed; $i++) {
            $shopId = $shopIds[$i % count($shopIds)];
            $availableModels = $modelsByShop[$shopId]->values();
            $selected = collect([0, 1, 2])->map(fn ($offset) => $availableModels[($i * 3 + $offset) % $availableModels->count()]);
            $userId = $userIds[$i % count($userIds)];
            $status = match ($i % 10) {
                0 => OrderStatus::Cancelled->value,
                1 => OrderStatus::Return->value,
                2 => OrderStatus::Delivering->value,
                3 => OrderStatus::Ready->value,
                4 => OrderStatus::Processing->value,
                default => OrderStatus::Delivered->value,
            };
            $createdAt = now()->subDays(random_int(0, 700))->subMinutes($i % 1440);
            $subtotal = $selected->sum(fn ($model) => round($model->price * (($i % 3) + 1), 2));
            $shipping = 2.5;
            $first = $selected->first();
            $orderId = DB::table('orders')->insertGetId([
                'order_code' => sprintf('ORD-SEED-%07d', DB::table('orders')->count() + 1),
                'name' => 'Sample multi-item order',
                'variations' => $first->variation_index,
                'quantity' => 3 * (($i % 3) + 1),
                'total' => $subtotal,
                'grand_total' => $subtotal + $shipping,
                'subtotal' => $subtotal,
                'shipping_fee' => $shipping,
                'discount_amount' => 0,
                'payment_total' => $subtotal + $shipping,
                'commission_rate' => 5,
                'commission_amount' => round(($subtotal + $shipping) * 0.05, 2),
                'user_id' => $userId,
                'product_id' => $first->product_id,
                'product_model_id' => $first->id,
                'received_address_id' => $addresses[$userId] ?? $addresses->first(),
                'pickup_address_id' => $addresses[$shopId] ?? $addresses->first(),
                'status_id' => $status,
                'shop_id' => $shopId,
                'created_at' => $createdAt,
            ]);

            $itemRows = [];
            foreach ($selected as $model) {
                $quantity = ($i % 3) + 1;
                $itemRows[] = [
                    'order_id' => $orderId,
                    'product_id' => $model->product_id,
                    'product_model_id' => $model->id,
                    'product_code' => $model->product_code,
                    'product_name' => $model->name,
                    'variations' => $model->variation_index,
                    'quantity' => $quantity,
                    'unit_price' => $model->price,
                    'line_total' => round($model->price * $quantity, 2),
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ];
            }
            DB::table('order_items')->insert($itemRows);
            $this->insertOrderProgresses($orderId, $status, $createdAt);
            DB::table('payments')->insert([
                'payment_code' => sprintf('PAY-SEED-%07d', $orderId),
                'order_id' => $orderId,
                'method' => ['cod', 'bank_transfer', 'e_wallet'][$i % 3],
                'amount' => $subtotal + $shipping,
                'paid_at' => in_array($status, [OrderStatus::Delivered->value, OrderStatus::Return->value]) ? $createdAt->copy()->addDays(3) : null,
                'status' => in_array($status, [OrderStatus::Delivered->value, OrderStatus::Return->value]) ? 'paid' : 'pending',
                'metadata' => null,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
            if (!in_array($status, [OrderStatus::Processing->value, OrderStatus::Cancelled->value])) {
                DB::table('shipments')->insert([
                    'tracking_code' => sprintf('TRACK-%07d', $orderId),
                    'order_id' => $orderId,
                    'carrier' => ['GHN', 'GHTK', 'Viettel Post'][$i % 3],
                    'picked_up_at' => $createdAt->copy()->addDay(),
                    'estimated_delivery_at' => $createdAt->copy()->addDays(4),
                    'delivered_at' => in_array($status, [OrderStatus::Delivered->value, OrderStatus::Return->value]) ? $createdAt->copy()->addDays(3) : null,
                    'shipping_fee' => $shipping,
                    'status' => in_array($status, [OrderStatus::Delivered->value, OrderStatus::Return->value]) ? 'delivered' : 'shipping',
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);
            }
        }

        $validVouchers = DB::table('vouchers')->where('ends_at', '>', now())->limit(10)->get();
        $orders = DB::table('orders')->whereNull('voucher_id')->where('subtotal', '>=', 20)->orderBy('id')->limit($validVouchers->count())->get();
        foreach ($orders as $index => $order) {
            $voucher = $validVouchers[$index];
            $discount = min(5, $order->subtotal);
            DB::table('orders')->where('id', $order->id)->update([
                'voucher_id' => $voucher->id,
                'discount_amount' => $discount,
                'payment_total' => max(0, $order->subtotal + $order->shipping_fee - $discount),
            ]);
            DB::table('voucher_usages')->insertOrIgnore([
                'voucher_id' => $voucher->id,
                'user_id' => $order->user_id,
                'order_id' => $order->id,
                'discount_amount' => $discount,
                'used_at' => now(),
            ]);
            DB::table('vouchers')->where('id', $voucher->id)->increment('used_count');
        }

        // Seed 2 historical audit records of expired voucher usage for the invalid-vouchers report query
        $expiredVouchers = DB::table('vouchers')->where('ends_at', '<=', now())->limit(2)->get();
        $unusedOrders = DB::table('orders')->whereNull('voucher_id')->orderByDesc('id')->limit(2)->get();
        foreach ($expiredVouchers as $idx => $expiredVoucher) {
            if (!isset($unusedOrders[$idx])) {
                break;
            }
            $ord = $unusedOrders[$idx];
            if (DB::table('voucher_usages')->where('voucher_id', $expiredVoucher->id)->exists()) {
                continue;
            }
            DB::table('orders')->where('id', $ord->id)->update(['voucher_id' => $expiredVoucher->id]);
            DB::table('voucher_usages')->insertOrIgnore([
                'voucher_id' => $expiredVoucher->id,
                'user_id' => $ord->user_id,
                'order_id' => $ord->id,
                'discount_amount' => 5,
                'used_at' => now(),
            ]);
        }

        $missingItems = 3000 - DB::table('order_items')->count();
        if ($missingItems > 0) {
            $orders = DB::table('orders')->orderBy('id')->limit($missingItems)->get();
            foreach ($orders as $order) {
                $model = DB::table('product_models as pm')->join('products as p', 'p.id', '=', 'pm.product_id')
                    ->where('p.shop_id', $order->shop_id)->select('pm.id', 'pm.price', 'pm.variation_index', 'p.id as product_id', 'p.product_code', 'p.name')->first()
                    ?? DB::table('product_models as pm')->join('products as p', 'p.id', '=', 'pm.product_id')
                        ->select('pm.id', 'pm.price', 'pm.variation_index', 'p.id as product_id', 'p.product_code', 'p.name')->first();
                DB::table('order_items')->insert([
                    'order_id' => $order->id,
                    'product_id' => $model->product_id,
                    'product_model_id' => $model->id,
                    'product_code' => $model->product_code,
                    'product_name' => $model->name,
                    'variations' => $model->variation_index,
                    'quantity' => 1,
                    'unit_price' => $model->price,
                    'line_total' => $model->price,
                    'created_at' => $order->created_at,
                    'updated_at' => $order->created_at,
                ]);
            }
        }
    }

    private function backfillLegacyOrders(): void
    {
        foreach (DB::table('orders')->orderBy('id')->get() as $order) {
            DB::table('orders')->where('id', $order->id)->update([
                'order_code' => $order->order_code ?: sprintf('ORD-LEGACY-%07d', $order->id),
                'subtotal' => $order->subtotal ?: $order->total,
                'payment_total' => $order->payment_total ?: $order->grand_total,
                'commission_rate' => 5,
                'commission_amount' => round(($order->payment_total ?: $order->grand_total) * 0.05, 2),
            ]);
            if (!DB::table('order_items')->where('order_id', $order->id)->exists()) {
                $product = DB::table('products')->where('id', $order->product_id)->first();
                $model = DB::table('product_models')->where('id', $order->product_model_id)->first();
                DB::table('order_items')->insert([
                    'order_id' => $order->id,
                    'product_id' => $order->product_id,
                    'product_model_id' => $order->product_model_id,
                    'product_code' => $product?->product_code,
                    'product_name' => $order->name,
                    'variations' => $order->variations,
                    'quantity' => $order->quantity,
                    'unit_price' => $order->quantity ? round($order->total / $order->quantity, 2) : ($model?->price ?? 0),
                    'line_total' => $order->total,
                    'created_at' => $order->created_at,
                    'updated_at' => $order->created_at,
                ]);
            }
            if (!DB::table('payments')->where('order_id', $order->id)->exists()) {
                DB::table('payments')->insert([
                    'payment_code' => sprintf('PAY-LEGACY-%07d', $order->id),
                    'order_id' => $order->id,
                    'method' => 'cod',
                    'amount' => $order->grand_total,
                    'paid_at' => $order->status_id == OrderStatus::Delivered->value ? $order->created_at : null,
                    'status' => $order->status_id == OrderStatus::Delivered->value ? 'paid' : 'pending',
                    'created_at' => $order->created_at,
                    'updated_at' => $order->created_at,
                ]);
            }
        }
    }

    private function insertOrderProgresses(int $orderId, int $status, $createdAt): void
    {
        $path = match ($status) {
            1 => [1],
            2 => [1, 2],
            3 => [1, 2, 3],
            4 => [1, 2, 3, 4],
            5 => [1, 5],
            6 => [1, 2, 3, 4, 6],
        };
        foreach ($path as $index => $statusId) {
            DB::table('order_progresses')->insert([
                'order_id' => $orderId,
                'status_id' => $statusId,
                'note' => 'Sample status history',
                'created_at' => $createdAt->copy()->addHours($index * 12),
            ]);
        }
    }

    private function seedReviews($faker): void
    {
        $comments = [
            'Sản phẩm chất lượng rất tốt, đóng gói cẩn thận, giao hàng nhanh!',
            'Đúng như mô tả, shop tư vấn nhiệt tình, rất đáng tiền.',
            'Hàng chính hãng, mẫu mã đẹp, sử dụng rất ưng ý.',
            'Giao hàng siêu tốc, chất lượng vượt mong đợi, sẽ ủng hộ shop tiếp.',
        ];
        // Ensure all products have customer reviews
        $sampleOrders = DB::table('orders')->orderBy('id')->limit(40)->get();
        $existingReviewProductIds = DB::table('product_reviews')->distinct()->pluck('product_id')->flip();
        foreach (DB::table('products')->orderBy('id')->get() as $idx => $product) {
            if ($existingReviewProductIds->has($product->id)) {
                continue;
            }
            $order = $sampleOrders[$idx % $sampleOrders->count()];
            DB::table('product_reviews')->insertOrIgnore([
                'user_id' => $order->user_id,
                'shop_id' => $product->shop_id,
                'product_id' => $product->id,
                'order_id' => $order->id,
                'rating' => ($idx % 2) === 0 ? 5 : 4,
                'comment' => $comments[$idx % count($comments)],
                'reply' => 'Cảm ơn bạn đã tin tưởng và ủng hộ gian hàng!',
                'variations' => '[]',
                'create_at' => now()->subDays(($idx % 30) + 1),
            ]);
        }

        $needed = 500 - DB::table('product_reviews')->count();
        $eligible = DB::table('orders as o')->join('order_items as oi', 'oi.order_id', '=', 'o.id')
            ->whereIn('o.status_id', [OrderStatus::Delivered->value, OrderStatus::Return->value])
            ->select('o.id as order_id', 'o.user_id', 'o.shop_id', 'oi.product_id', 'oi.variations')->get();
        $index = 0;
        foreach ($eligible as $row) {
            if ($needed <= 0 || DB::table('product_reviews')->count() >= 500) {
                break;
            }
            DB::table('product_reviews')->insertOrIgnore([
                'user_id' => $row->user_id,
                'shop_id' => $row->shop_id,
                'product_id' => $row->product_id,
                'order_id' => $row->order_id,
                'rating' => ($index % 5) + 1,
                'comment' => $comments[$index % count($comments)],
                'reply' => '',
                'variations' => $row->variations ?: '[]',
                'create_at' => now()->subDays(random_int(0, 365)),
            ]);
            $index++;
        }
    }

    private function seedFavorites(): void
    {
        $productIds = DB::table('products')->limit(50)->pluck('id')->all();
        foreach (DB::table('users')->limit(100)->pluck('id') as $userId) {
            foreach ([$productIds[$userId % count($productIds)], $productIds[($userId + 7) % count($productIds)]] as $productId) {
                DB::table('favorites')->insertOrIgnore(['user_id' => $userId, 'product_id' => $productId, 'created_at' => now()]);
            }
        }
    }

    private function seedPriceHistory(): void
    {
        if (DB::table('product_price_histories')->count() > 0) {
            return;
        }
        foreach (DB::table('product_models')->orderBy('id')->limit(50)->get() as $model) {
            $base = (float) $model->price;
            DB::table('product_price_histories')->insert([
                ['product_model_id' => $model->id, 'old_price' => $base * 0.8, 'new_price' => $base * 0.9, 'effective_at' => now()->subMonths(2)],
                ['product_model_id' => $model->id, 'old_price' => $base * 0.9, 'new_price' => $base, 'effective_at' => now()->subMonth()],
            ]);
        }
    }

    private function seedAuxiliaryBusinessTables(): void
    {
        foreach (DB::table('administrators')->orderBy('id')->get() as $admin) {
            DB::table('administrator_profiles')->updateOrInsert(
                ['admin_id' => $admin->id],
                [
                    'display_name' => 'Quản trị viên #' . $admin->id,
                    'avatar_image' => 'demo-avatar-01',
                    'phone' => sprintf('09000000%02d', $admin->id),
                    'gender' => 1,
                    'date_of_birth' => '1995-05-15',
                ],
            );
        }

        if (DB::table('bank_accounts')->count() === 0) {
            foreach (DB::table('users')->orderBy('id')->limit(10)->get() as $user) {
                DB::table('bank_accounts')->insertOrIgnore([
                    'bank_accountable_type' => User::class,
                    'bank_accountable_id' => $user->id,
                    'accountholder_name' => 'NGUYEN VAN KHACH ' . $user->id,
                    'identification_number' => sprintf('%012d', $user->id),
                    'bank_name' => 'Vietcombank',
                    'bank_branch' => 'Chi nhánh Hà Nội',
                    'account_number' => sprintf('1020304050%03d', $user->id),
                ]);
            }
        }

        if (DB::table('credit_cards')->count() === 0) {
            foreach (DB::table('users')->orderBy('id')->limit(10)->get() as $user) {
                DB::table('credit_cards')->insertOrIgnore([
                    'credit_cardable_type' => User::class,
                    'credit_cardable_id' => $user->id,
                    'cardholder_name' => 'NGUYEN VAN KHACH ' . $user->id,
                    'card_number' => sprintf('411111111111%04d', $user->id),
                    'expiry_date' => '12/28',
                    'cvv' => '123',
                    'registration_address' => 'Hà Nội, Việt Nam',
                    'postal_code' => '10000',
                ]);
            }
        }

        if (DB::table('product_brand_product_category')->count() === 0) {
            $brandId = DB::table('product_brands')->value('id') ?: 1;
            foreach ([1, 19, 47, 60, 72, 85, 97, 109, 121, 134] as $catId) {
                DB::table('product_brand_product_category')->insertOrIgnore([
                    'brand_id' => $brandId,
                    'category_id' => $catId,
                ]);
            }
        }

        if (DB::table('seller_applications')->count() === 0) {
            $applicantId = DB::table('users')->where('id', '>', 20)->value('id') ?: 21;
            DB::table('seller_applications')->insert([
                'user_id' => $applicantId,
                'name' => 'E-Shopee Partner Store',
                'phone' => '0912345678',
                'email' => 'partner@example.com',
                'address' => 'Cầu Giấy, Hà Nội',
                'tax_code' => 'TAX-9999999999',
                'status' => 'pending',
                'review_note' => 'Đang chờ duyệt hồ sơ đăng ký bán hàng',
                'reviewed_by' => null,
                'reviewed_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
