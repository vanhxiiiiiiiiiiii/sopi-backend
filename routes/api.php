<?php

use App\Models\Account\Admin\Admin;
use App\Models\Account\User\User;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::prefix('v2')->namespace('Api')->group(function () {
    Route::post('/delivery/auth/sign-in', 'DeliveryAuthController@login')->middleware('throttle:5,1');
    Route::prefix('delivery')->middleware(['auth:sanctum', \App\Http\Middleware\RequireDeliveryStaff::class])->group(function () {
        Route::get('/me', 'DeliveryAuthController@me');
        Route::post('/auth/sign-out', 'DeliveryAuthController@logout');
        Route::get('/dashboard', 'ShipmentController@dashboard');
        Route::get('/history', 'ShipmentController@history');
        Route::get('/shipments', 'ShipmentController@index');
        Route::get('/shipments/{id}', 'ShipmentController@show');
        Route::post('/shipments/{id}/status', 'ShipmentController@update');
        Route::post('/shipments/{id}/collect-cod', 'ShipmentController@collect');
    });
    Route::get('/promotions/{campaign}', [\App\Http\Controllers\Api\PromotionController::class, 'show'])
        ->whereIn('campaign', array_keys(config('promotions')));
    Route::prefix('resource')->middleware('allow:*')
        ->group(function () {
            Route::post('/images', 'ResourceController@uploadImage');
        });

    Route::middleware('allow:' . User::class)->group(function () {
        // User endpoints
        Route::prefix('user')->group(function () {
            Route::get('/shipping/settings', 'ShippingRateController@show')->middleware('auth:sanctum');
            Route::post('/shipping/search', 'ShippingRateController@search')->middleware(['auth:sanctum', 'throttle:20,1']);
            Route::prefix('auth')->namespace('Auth')->group(function () {
                Route::post('/sign-up', 'SignUpController@signUp')
                    ->withoutMiddleware('allow:' . User::class);
                Route::post('/sign-in', 'SignInController@signInAsUser')
                    ->withoutMiddleware('allow:' . User::class);
                Route::post('/sign-out', 'SignInController@signOut');

                Route::prefix('oauth/{driver}')->group(function () {
                    Route::get('/redirect', 'SignUpController@OAuthRedirect');
                    Route::get('/callback', 'SignUpController@OAuthCallback');
                });
            });

            Route::prefix('email')->namespace('Email')->group(function () {
                Route::post('/verify', 'EmailVerificationController@sendEmail')
                    ->middleware('throttle:10,1');
                Route::get('/verify/{id}/{hash}', 'EmailVerificationController@verifyEmail')
                    ->name('verification.verify');
            });

            Route::prefix('password')->namespace('Password')->group(function () {
                Route::put('/', 'PasswordController@update');

                Route::post('/forgot', 'PasswordController@forgotUserPassword')
                    ->withoutMiddleware('allow:' . User::class);
                Route::post('/reset', 'PasswordController@resetUserPassword')
                    ->name('user.password.reset')
                    ->withoutMiddleware('allow:' . User::class);
            });

            Route::prefix('account')->namespace('Account')->group(function () {
                Route::get('/vouchers', [\App\Http\Controllers\Api\VoucherController::class, 'available']);
                Route::post('/vouchers/{voucher}/save', [\App\Http\Controllers\Api\PromotionController::class, 'save'])
                    ->middleware('auth:sanctum');
                Route::prefix('profile')->namespace('Profile')->group(function () {
                    Route::get('/', 'UserProfileController@me');
                    Route::put('/', 'UserProfileController@update');
                });

                Route::prefix('addresses')->namespace('Address')->group(function () {
                    Route::get('/', 'UserAddressController@all');
                    Route::post('/', 'UserAddressController@create');
                    Route::put('/{id}', 'UserAddressController@update');
                    Route::delete('/{address}', 'UserAddressController@delete');
                });

                Route::prefix('bank-accounts')->group(function () {
                    Route::get('/', 'BankAccountController@all');
                    Route::post('/', 'BankAccountController@create');
                    Route::put('/{bank_account}', 'BankAccountController@update');
                    Route::delete('/{bank_account}', 'BankAccountController@delete');
                });
                Route::prefix('credit-cards')->group(function () {
                    Route::get('/', 'CreditCardController@show');
                    Route::post('/', 'CreditCardController@create');
                    Route::put('/{credit_card}', 'CreditCardController@update');
                    Route::delete('/{credit_card}', 'CreditCardController@delete');
                });
            });

            Route::namespace('Order')->group(function () {
                Route::prefix('cart')->group(function () {
                    Route::get('/', 'CartController@get');
                    Route::post('/', 'CartController@add');
                    Route::delete('/{product_model_id}', 'CartController@delete');
                });

                Route::prefix('orders')->group(function () {
                    Route::post('/quote', 'OrderController@quote');
                    Route::get('/', 'OrderController@belongToUser');
                    Route::get('/{id}', 'OrderController@get');
                    Route::post('/', 'OrderController@create');
                    Route::post('/{order}/buy-again', 'OrderController@buyAgain');

                    Route::prefix('/{order}')->group(function () {
                        Route::post('/cancel', 'ProgressController@cancel');
                    });
                });
            });

            Route::get('/favorites', 'FavoriteController@index');
            Route::get('/seller-application', 'SellerApplicationController@mine');
            Route::post('/seller-application', 'SellerApplicationController@submit');
            Route::post('/favorites', 'FavoriteController@store');
            Route::delete('/favorites/{productId}', 'FavoriteController@destroy');
            Route::post('/payments/{payment}/pay', 'PaymentController@pay')->middleware('auth:sanctum');
            Route::get('/payment-methods', 'PaymentMethodController@index')->middleware('auth:sanctum');
            Route::post('/payment-methods/setup', 'PaymentMethodController@setup')->middleware('auth:sanctum');

            Route::namespace('Product')->group(function () {
                Route::prefix('reviews')->group(function () {
                    Route::post('/', 'ReviewController@create');
                });
            });
        });

        // Shop endpoints
        Route::prefix('/shop')->middleware(['auth:sanctum', \App\Http\Middleware\RequireSellerShop::class])->group(function () {
            Route::get('/dashboard', 'SellerApplicationController@dashboard');
            Route::get('/warehouses', 'InventoryController@index');
            Route::post('/warehouses', 'InventoryController@storeWarehouse');
            Route::post('/inventory/adjust', 'InventoryController@adjust');
            Route::namespace('Shop')->group(function () {
                Route::get('/', 'ShopController@myShop');
                Route::put('/', 'ShopController@update');
            });

            Route::prefix('products')->namespace('Product')->group(function () {
                Route::get('/', 'ProductController@belongToShop');
                Route::post('/', 'ProductController@create');
                Route::put('/{product}', 'ProductController@update');
                Route::patch('/{product}', 'ProductController@updateListing');
                Route::delete('/{product}', 'ProductController@delete');

                Route::prefix('categories')->group(function () {
                    Route::get('/attributes', 'CategoryController@attributes');
                });

                Route::prefix('reviews')->group(function () {
                    Route::get('/', 'ReviewController@belongToShop');
                    Route::post('/{review}/reply', 'ReviewController@reply');
                });
            });

            Route::prefix('orders')->namespace('Order')->group(function () {
                Route::get('/', 'OrderController@belongToShop');

                Route::prefix('/{order}')->group(function () {
                    Route::post('/confirm', 'ProgressController@confirm');
                    Route::post('/prepare', 'ProgressController@prepare');
                    Route::post('/ready', 'ProgressController@ready');
                    Route::post('/cancel', 'ProgressController@cancel');
                    Route::put('/status', 'ProgressController@update');
                });
            });
        });
    });

    // Admin endpoints
    Route::prefix('/admin')->middleware(['auth:sanctum', 'allow:' . Admin::class, \App\Http\Middleware\RequireAdminRole::class])->group(function () {
        Route::get('/me', 'AdminAccessController@me');
        Route::get('/overview', 'AdminAccessController@overview');
        Route::get('/roles', 'AdminAccessController@roles');
        Route::get('/administrators', 'AdminAccessController@index');
        Route::post('/administrators', 'AdminAccessController@store');
        Route::put('/administrators/{id}', 'AdminAccessController@update');
        Route::get('/activity', 'AdminAccessController@activity');
        Route::get('/products', 'AdminCommerceController@products');
        Route::patch('/products/{product}/moderation', 'AdminCommerceController@moderate');
        Route::get('/orders', 'AdminCommerceController@orders');
        Route::get('/payments', 'AdminCommerceController@payments');
        Route::get('/shipping/providers', 'DeliveryManagementController@providers');
        Route::get('/shipping/rates', 'ShippingRateController@show');
        Route::put('/shipping/rates', 'ShippingRateController@update');
        Route::post('/shipping/search', 'ShippingRateController@search')->middleware('throttle:20,1');
        Route::post('/shipping/providers', 'DeliveryManagementController@saveProvider');
        Route::put('/shipping/providers/{id}', 'DeliveryManagementController@saveProvider');
        Route::get('/shipping/staff', 'DeliveryManagementController@staff');
        Route::post('/shipping/staff', 'DeliveryManagementController@saveStaff');
        Route::put('/shipping/staff/{id}', 'DeliveryManagementController@saveStaff');
        Route::get('/shipments', 'ShipmentController@index');
        Route::get('/shipments/dashboard', 'ShipmentController@dashboard');
        Route::get('/shipments/{id}', 'ShipmentController@show');
        Route::post('/shipments/{id}/assign', 'ShipmentController@assign');
        Route::post('/shipments/{id}/request-return', 'ShipmentController@requestReturn');
        Route::get('/seller-applications', 'SellerApplicationController@index');
        Route::put('/seller-applications/{id}', 'SellerApplicationController@review');
        Route::get('/dashboard', 'ReportController@dashboard');
        Route::get('/reports/cache-benchmark', 'ReportController@cacheBenchmark');
        Route::get('/reports/{report}', 'ReportController@index');
        Route::get('/vouchers', 'VoucherController@index');
        Route::post('/vouchers', 'VoucherController@store');
        Route::put('/vouchers/{voucher}', 'VoucherController@update');
        Route::delete('/vouchers/{voucher}', 'VoucherController@destroy');
        Route::get('/customers', 'AccountManagementController@customers');
        Route::put('/customers/{user}', 'AccountManagementController@updateCustomer');
        Route::get('/sellers', 'AccountManagementController@sellers');
        Route::put('/sellers/{shop}', 'AccountManagementController@updateSeller');
        Route::prefix('auth')->namespace('Auth')->group(function () {
            Route::post('/sign-in', 'SignInController@signInAsAdmin')
                ->withoutMiddleware(['auth:sanctum', 'allow:' . Admin::class, \App\Http\Middleware\RequireAdminRole::class]);
            Route::post('/sign-out', 'SignInController@signOut');
        });

        Route::prefix('password')->namespace('Password')->group(function () {
            Route::put('/', 'PasswordController@update');

            Route::post('/forgot', 'PasswordController@forgotAdminPassword')
                ->withoutMiddleware(['auth:sanctum', 'allow:' . Admin::class, \App\Http\Middleware\RequireAdminRole::class]);
            Route::post('/reset', 'PasswordController@resetAdminPassword')
                ->name('admin.password.reset')
                ->withoutMiddleware(['auth:sanctum', 'allow:' . Admin::class, \App\Http\Middleware\RequireAdminRole::class]);
        });

        Route::prefix('products')->namespace('Product')->group(function () {
            Route::post('/{product}/recovery', 'ProductController@recovery');

            Route::prefix('categories')->group(function () {
                Route::post('/', 'CategoryController@manage');

                Route::get('/attributes', 'CategoryController@attributes');
                Route::post('/{category}/bind-attributes', 'CategoryController@bindAttributes')
                    ->where('category', '[1-9]+');

                Route::prefix('attributes')->group(function () {
                    Route::get('/{input}', 'AttributeController@search');
                    Route::post('/', 'AttributeController@create');
                    Route::put('/{id}', 'AttributeController@update');
                    Route::delete('/{id}', 'AttributeController@delete');
                });
            });
        });

        Route::prefix('orders')->namespace('Order')->group(function () {
            Route::prefix('/{order}')->group(function () {
                Route::post('/cancel', 'ProgressController@cancel');
                Route::put('/status', 'ProgressController@update');
            });
        });
    });

    // Publish shop endpoints
    Route::prefix('shops')->group(function () {
        Route::namespace('Shop')->group(function () {
            Route::get('/{id_or_slug}', 'ShopController@get');
        });
        Route::namespace('Product')->group(function () {
            Route::get('/{shop_id}/products', 'ProductController@publishedBelongToShop');
        });
    });

    // Publish product endpoint
    Route::prefix('products')->namespace('Product')->group(function () {
        Route::get('/feature', 'ProductController@feature');
        Route::get('/search', 'ProductController@search');

        Route::prefix('{id}')->group(function () {
            Route::get('/', 'ProductController@get');
            Route::get('/reviews', 'ReviewController@belongToProduct');
        });

        Route::prefix('categories')->group(function () {
            Route::get('/tree', 'CategoryController@tree');
        });
    });
});
