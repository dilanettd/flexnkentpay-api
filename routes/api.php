<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\SellerController;
use App\Http\Controllers\ShopController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductImageController;
use App\Http\Controllers\ShopReviewController;
use App\Http\Controllers\ProductReviewController;
use App\Http\Controllers\MomoTransactionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PawaPayController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\OrderPaymentController;
use App\Http\Controllers\FeeController;


/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Authentication routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::delete('/logout', [AuthController::class, 'logout'])->middleware('auth:api');
Route::post('/email/verify', [AuthController::class, 'verifyAccountEmail']);
Route::middleware('auth:api')->get('/user', [AuthController::class, 'getAuthenticatedUser']);

// User routes (authenticated)
Route::middleware('auth:api')->post('/user/profile-picture', [UserController::class, 'updateProfilePicture']);
Route::middleware('auth:api')->put('/user/profile', [UserController::class, 'updateProfile']);
Route::middleware(['auth:api', 'role:admin'])->post('/user/change-role', [UserController::class, 'changeRole']);

// Seller routes (authenticated)
Route::middleware(['auth:api'])->group(function () {
    Route::get('/seller/dashboard', [SellerController::class, 'dashboard']);
    // other routes for sellers
});

// Seller-specific routes accessible without authentication
Route::get('/shops/top-rated', [ShopController::class, 'getTopRatedShops']);
Route::get('/seller', [SellerController::class, 'getSellerDetails']);
Route::get('/shop/{id}', [ShopController::class, 'getShopById']);
Route::get('/seller/dashboard', [SellerController::class, 'dashboard']);
Route::get('shops/{shopId}/products', [ProductController::class, 'getProductsByShop']);
Route::get('shop-reviews/{shopId}', [ShopReviewController::class, 'index']);
Route::post('/shop/{shopId}/increment-visit', [ShopController::class, 'incrementVisitCount']);


// Seller-specific routes that require authentication
Route::middleware(['auth:api'])->group(function () {
    Route::post('/shop/logo', [ShopController::class, 'updateLogo']);
    Route::post('/shop/cover-image', [ShopController::class, 'updateCoverImage']);
    Route::put('/shop/details', [ShopController::class, 'updateDetails']);
    Route::post('shop-review', [ShopReviewController::class, 'store']);
});

// Password reset routes
Route::post('/password/email', [AuthController::class, 'sendResetPasswordEmail']);
Route::post('/password/reset', [AuthController::class, 'resetPassword']);

// Product routes accessible without authentication
Route::get('/products/search', [ProductController::class, 'search']);
Route::get('/product/{id}', [ProductController::class, 'show']);
Route::get('/products/recent', [ProductController::class, 'recentProducts']);
Route::get('/products/{id}/related', [ProductController::class, 'relatedProducts']);
Route::post('/products/{product}/increment-views', [ProductController::class, 'incrementViews']);
Route::get('product-reviews/{productId}', [ProductReviewController::class, 'index']);


// Product routes (authenticated)
Route::middleware('auth:api')->group(function () {
    Route::get('/products', [ProductController::class, 'index']);
    Route::post('/product', [ProductController::class, 'store']);
    Route::put('/product/{id}', [ProductController::class, 'update']);
    Route::delete('/product/{id}', [ProductController::class, 'destroy']);
    Route::post('product-review', [ProductReviewController::class, 'store']);

});

// Product Image routes (authenticated)
Route::middleware('auth:api')->group(function () {
    Route::get('/product-images', [ProductImageController::class, 'index']);
    Route::post('/product-images', [ProductImageController::class, 'store']);
    Route::get('/product-images/{path}', [ProductImageController::class, 'show']);
    Route::delete('/product-images/{id}', [ProductImageController::class, 'destroy']);
    Route::get('/products/code/{code}', [ProductController::class, 'findByProductCode'])->name('products.findByCode');

});

// Customer routes (authenticated)
Route::middleware(['auth:api', 'role:customer'])->group(function () {
    Route::get('/customer/dashboard', [CustomerController::class, 'dashboard']);
    // other routes for customers
});

// Transactions MoMo
Route::middleware('auth:api')->group(function () {
    Route::post('/momo-transaction', [MomoTransactionController::class, 'store']);
    Route::get('/momo-transactions/user', [MomoTransactionController::class, 'getUserTransactions']);
});

// Orders
Route::middleware('auth:api')->group(function () {
    Route::post('/order', [OrderController::class, 'store']);
    Route::get('/orders/user', [OrderController::class, 'getUserOrders']);
    Route::get('/orders/seller', [OrderController::class, 'getSellerOrders']);
    Route::delete('/orders/{id}/cancel', [OrderController::class, 'cancelOrder']);
    Route::post('/orders/{orderId}/retry-confirmation', [OrderController::class, 'retryConfirmationPayment']);
});


//Admin 
Route::middleware('auth:api')->group(function () {
    Route::post('/admin/create', [AuthController::class, 'createAdmin']);
});
Route::post('/admin/first', [AuthController::class, 'createFirstAdmin']);
Route::post('/admin/login', [AuthController::class, 'loginAdmin']);
Route::post('/admin/add-role', [AuthController::class, 'addRoleToAdmin']);
Route::delete('/admin/remove-privileges', [AuthController::class, 'removeAdminPrivileges']);
Route::post('/admin/add-permissions', [AuthController::class, 'addPermissionsToAdmin']);


//Dashboard
Route::middleware('auth:api')->group(function () {
    Route::put('/admin/users/{id}/status', [UserController::class, 'updateStatus']);
    Route::get('/dashboard-stats', [DashboardController::class, 'getDashboardStats']);
    Route::get('/admin/users', [UserController::class, 'getAllUsers']);
    Route::get('/admin/orders', [OrderController::class, 'getAllOrders']);
    Route::get('/admin/products', [ProductController::class, 'getAdminProducts']);
    Route::post('/admin/products/{id}/toggle', [ProductController::class, 'toggleActive']);
    Route::get('/admin/transactions', [MomoTransactionController::class, 'getAllTransactions']);
});



// Routes PawaPay
Route::post('/pawapay/{eventType}/webhook', [PawaPayController::class, 'handleWebhook']);

// Routes pour les paiements Mobile Money avec PawaPay (nécessitent authentification)
Route::middleware('auth:api')->group(function () {
    Route::post('/orders/pay-with-momo', [PawaPayController::class, 'initiatePayment']);
    Route::get('/pawapay/transactions', [PawaPayController::class, 'getUserTransactions']);
    Route::get('/pawapay/deposits/{providerTransactionId}/status', [PawaPayController::class, 'checkDepositStatus']);
});

// Routes pour les paiements des commandes
Route::middleware('auth:api')->group(function () {
    Route::post('/order-payments/pay', [OrderPaymentController::class, 'initiatePayment']);
    Route::get('/order-payments/pending', [OrderPaymentController::class, 'getPendingPayments']);
    Route::get('/order-payments/orders/{orderId}', [OrderPaymentController::class, 'getOrderPayments']);
    Route::get('/order-payments/{paymentId}', [OrderPaymentController::class, 'getPaymentDetails']);
});

// Routes pour la gestion des frais (Fees)
Route::middleware(['auth:api'])->group(function () {
    Route::get('/fees/{type}', [FeeController::class, 'show']);
    Route::get('/fees', [FeeController::class, 'index']);
    Route::post('/fees', [FeeController::class, 'store']);
    Route::put('/fees/{type}', [FeeController::class, 'update']);
    Route::delete('/fees/{type}', [FeeController::class, 'destroy']);
    Route::put('/fees/{type}/activate', [FeeController::class, 'activate']);
    Route::get('/fees/active', [FeeController::class, 'getActiveFees']);
});


// Routes pour la gestion des frais (Fees)
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/categories/{id}', [CategoryController::class, 'show']);
Route::middleware(['auth:api'])->group(function () {
    Route::post('/categories', [CategoryController::class, 'store']);
    Route::put('/categories/{id}', [CategoryController::class, 'update']);
    Route::delete('/categories/{id}', [CategoryController::class, 'destroy']);
});