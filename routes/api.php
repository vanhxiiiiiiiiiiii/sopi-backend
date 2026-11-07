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
    Route::prefix('resource')->middleware('allow:*')
        ->group(function () {
            Route::post('/images', 'ResourceController@uploadImage');
        });

    Route::middleware('allow:' . User::class)->group(function () {
        // User endpoints
        Route::prefix('user')->group(function () {
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
                    Route::post('/ready', 'ProgressController@ready');
                    Route::post('/cancel', 'ProgressController@cancel');
                    Route::put('/status', 'ProgressController@update');
                });
            });
        });
    });

    // Admin endpoints
    Route::prefix('/admin')->middleware('allow:' . Admin::class)->group(function () {
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
                ->withoutMiddleware('allow:' . Admin::class);
            Route::post('/sign-out', 'SignInController@signOut');
        });

        Route::prefix('password')->namespace('Password')->group(function () {
            Route::put('/', 'PasswordController@update');

            Route::post('/forgot', 'PasswordController@forgotAdminPassword')
                ->withoutMiddleware('allow:' . Admin::class);
            Route::post('/reset', 'PasswordController@resetAdminPassword')
                ->name('admin.password.reset')
                ->withoutMiddleware('allow:' . Admin::class);
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
