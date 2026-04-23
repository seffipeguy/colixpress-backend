<?php

use App\Core\Auth;
use App\Core\ApiAuth;

/**
 * ColiXpress API Routes
 * 
 * Public routes (no auth required)
 * Protected routes (Bearer token required)
 * Developer routes (API key required)
 */

// =============================================
// PUBLIC ROUTES — No authentication
// =============================================

// Health check
$router->get('/api/health', App\Controllers\AuthController::class, 'health');

// Countries list
$router->get('/api/countries', App\Controllers\AuthController::class, 'countries');

// Auth - OTP
$router->post('/api/auth/send-otp', App\Controllers\AuthController::class, 'sendOtp');
$router->post('/api/auth/verify-otp', App\Controllers\AuthController::class, 'verifyOtp');

// Auth - Phone + Password
$router->post('/api/auth/register', App\Controllers\AuthController::class, 'register');
$router->post('/api/auth/login', App\Controllers\AuthController::class, 'login');

// Public shop browsing
$router->get('/api/shops', App\Controllers\ShopController::class, 'index');
$router->get('/api/shops/popular', App\Controllers\ShopController::class, 'popular');
$router->get('/api/shops/nearby', App\Controllers\ShopController::class, 'nearby');
$router->get('/api/shops/{id}', App\Controllers\ShopController::class, 'show');

// Shop categories & tags
$router->get('/api/shop-categories', App\Controllers\ShopController::class, 'categories');
$router->get('/api/shop-tags', App\Controllers\ShopController::class, 'tags');

// Google Custom Search for Shops
$router->get('/api/search/shops', App\Controllers\SearchController::class, 'searchShops');

// Pricing info (public)
$router->get('/api/pricing', App\Controllers\PricingController::class, 'index');
$router->get('/api/pricing/{city}', App\Controllers\PricingController::class, 'show');

// Public app settings (non-sensitive)
$router->get('/api/settings/public', App\Controllers\SettingsController::class, 'publicSettings');
$router->get('/api/settings/maps-pricing', App\Controllers\SettingsController::class, 'mapsPricing');
$router->get('/api/settings/app-version', App\Controllers\SettingsController::class, 'appVersion');

// Maps (public)
$router->get('/api/maps/autocomplete', App\Controllers\MapsController::class, 'autocomplete');
$router->get('/api/maps/place-details', App\Controllers\MapsController::class, 'placeDetails');

// VAPID public key (public)
$router->get('/api/push/vapid-public-key', App\Controllers\PushController::class, 'vapidPublicKey');

// Banners / News (public, with optional auth for role targeting)
$router->get('/api/banners', App\Controllers\BannerController::class, 'index');
$router->get('/api/tracking/{reference}', App\Controllers\OrderController::class, 'publicTracking');
$router->put('/api/orders/{reference}', App\Controllers\OrderController::class, 'update');

// Order Templates (Public)
$router->get('/api/templates/check-slug', App\Controllers\OrderTemplateController::class, 'checkSlug');
$router->get('/api/templates/{slug}', App\Controllers\OrderTemplateController::class, 'show');
$router->post('/api/templates/{slug}/instantiate', App\Controllers\OrderTemplateController::class, 'instantiate');


// =============================================
// PROTECTED ROUTES — Require Bearer token
// =============================================

$router->group('', [Auth::class], function ($router) {

    // --- Order Templates (Private) ---
    $router->get('/api/templates', App\Controllers\OrderTemplateController::class, 'index');
    $router->post('/api/templates', App\Controllers\OrderTemplateController::class, 'store');
    $router->put('/api/templates/{slug}', App\Controllers\OrderTemplateController::class, 'update');
    $router->delete('/api/templates/{slug}', App\Controllers\OrderTemplateController::class, 'destroy');
    $router->post('/api/templates/{slug}/media', App\Controllers\OrderTemplateController::class, 'uploadMedia');
    $router->delete('/api/templates/{slug}/media', App\Controllers\OrderTemplateController::class, 'deleteMedia');

    // --- Companies ---
    $router->get('/api/companies/me', App\Controllers\CompanyController::class, 'me');
    $router->post('/api/companies', App\Controllers\CompanyController::class, 'store');
    $router->put('/api/companies/{id}', App\Controllers\CompanyController::class, 'update');
    $router->get('/api/companies/{id}/livreurs', App\Controllers\CompanyController::class, 'livreurs');
    $router->post('/api/companies/{id}/livreurs', App\Controllers\CompanyController::class, 'addLivreur');
    $router->delete('/api/companies/{id}/livreurs/{livreur_id}', App\Controllers\CompanyController::class, 'removeLivreur');
    $router->get('/api/companies/{id}/shops', App\Controllers\CompanyController::class, 'shops');
    $router->post('/api/companies/{id}/shops', App\Controllers\CompanyController::class, 'addShop');
    $router->delete('/api/companies/{id}/shops/{shop_id}', App\Controllers\CompanyController::class, 'removeShop');

    // --- Media Upload ---
    $router->post('/api/media/upload', App\Controllers\MediaController::class, 'upload');
    $router->delete('/api/media/{reference}', App\Controllers\MediaController::class, 'destroy');

    // --- Auth ---
    $router->post('/api/auth/logout', App\Controllers\AuthController::class, 'logout');

    // --- Maps (proxy cache Google Maps) ---
    $router->get('/api/maps/geocode', App\Controllers\MapsController::class, 'geocode');
    $router->get('/api/maps/reverse-geocode', App\Controllers\MapsController::class, 'reverseGeocode');
    $router->get('/api/maps/directions', App\Controllers\MapsController::class, 'directions');

    // --- Pricing Calculator ---
    $router->post('/api/pricing/calculate', App\Controllers\PricingController::class, 'calculatePrice');

    $router->get('/api/auth/me', App\Controllers\AuthController::class, 'me');
    $router->put('/api/auth/password', App\Controllers\AuthController::class, 'changePassword');

    // --- User Profile ---
    $router->get('/api/user/profile', App\Controllers\UserController::class, 'profile');
    $router->put('/api/user/profile', App\Controllers\UserController::class, 'updateProfile');
    $router->post('/api/user/profile-photo', App\Controllers\UserController::class, 'updatePhoto');
    $router->delete('/api/user/account', App\Controllers\UserController::class, 'deleteAccount');

    // --- Addresses ---
    $router->get('/api/addresses', App\Controllers\AddressController::class, 'index');
    $router->post('/api/addresses', App\Controllers\AddressController::class, 'store');
    $router->get('/api/addresses/{id}', App\Controllers\AddressController::class, 'show');
    $router->put('/api/addresses/{id}', App\Controllers\AddressController::class, 'update');
    $router->delete('/api/addresses/{id}', App\Controllers\AddressController::class, 'destroy');

    // --- Carts ---
    $router->get('/api/carts', App\Controllers\CartController::class, 'index');
    $router->post('/api/carts', App\Controllers\CartController::class, 'store');
    $router->get('/api/carts/{reference}', App\Controllers\CartController::class, 'show');
    $router->post('/api/carts/{reference}/close', App\Controllers\CartController::class, 'close');

    // --- Orders ---
    $router->get('/api/orders', App\Controllers\OrderController::class, 'index');
    $router->post('/api/orders', App\Controllers\OrderController::class, 'store');
    $router->get('/api/orders/pending', App\Controllers\OrderController::class, 'pending');
    $router->get('/api/orders/estimate', App\Controllers\OrderController::class, 'estimate');
    $router->get('/api/orders/active-summary', App\Controllers\OrderController::class, 'activeSummary');
    $router->get('/api/orders/frequent-places', App\Controllers\OrderController::class, 'frequentPlaces');
    $router->get('/api/orders/frequent-shops', App\Controllers\OrderController::class, 'frequentShops');
    $router->get('/api/orders/{reference}', App\Controllers\OrderController::class, 'show');
    // $router->put('/api/orders/{id}', App\Controllers\OrderController::class, 'update'); // Moved to public
    $router->put('/api/orders/{reference}/accept', App\Controllers\OrderController::class, 'accept');
    $router->put('/api/orders/{reference}/status', App\Controllers\OrderController::class, 'updateStatus');
    $router->put('/api/orders/{reference}/cancel', App\Controllers\OrderController::class, 'cancel');
    $router->get('/api/orders/{reference}/tracking', App\Controllers\OrderController::class, 'tracking');

    // --- Order Media ---
    $router->get('/api/orders/{reference}/media', App\Controllers\OrderMediaController::class, 'index');
    $router->post('/api/orders/{reference}/media', App\Controllers\OrderMediaController::class, 'upload');
    $router->delete('/api/orders/{reference}/media/{id}', App\Controllers\OrderMediaController::class, 'destroy');

    // --- Order Ratings ---
    $router->post('/api/orders/{reference}/rating', App\Controllers\RatingController::class, 'store');

    // --- Order Messages (messagerie par commande) ---
    $router->get('/api/orders/{reference}/messages',      App\Controllers\OrderMessageController::class, 'index');
    $router->post('/api/orders/{reference}/messages',     App\Controllers\OrderMessageController::class, 'store');
    $router->patch('/api/orders/{reference}/messages/read', App\Controllers\OrderMessageController::class, 'markRead');

    // --- Dispatching / Affrètement ---
    $router->get('/api/dispatch/pool',                    App\Controllers\DispatcherController::class, 'pool');
    $router->get('/api/dispatch/orders/mine',             App\Controllers\DispatcherController::class, 'myClaimed');
    $router->post('/api/dispatch/orders/{id}/claim',      App\Controllers\DispatcherController::class, 'claim');
    $router->post('/api/dispatch/orders/{id}/release',    App\Controllers\DispatcherController::class, 'release');
    $router->post('/api/dispatch/orders/{id}/assign',     App\Controllers\DispatcherController::class, 'assign');
    $router->patch('/api/dispatch/orders/{id}/notes',     App\Controllers\DispatcherController::class, 'updateNotes');
    $router->get('/api/dispatch/livreurs',                App\Controllers\DispatcherController::class, 'livreurs');
    $router->get('/api/dispatch/companies',               App\Controllers\DispatcherController::class, 'companies');

    // --- Shops (owner management) ---
    $router->post('/api/shops', App\Controllers\ShopController::class, 'store');
    $router->put('/api/shops/{id}', App\Controllers\ShopController::class, 'update');
    $router->get('/api/shops/my', App\Controllers\ShopController::class, 'myShops');
    $router->put('/api/shops/{id}/approve', App\Controllers\ShopController::class, 'approve');
    $router->put('/api/shops/{id}/feature', App\Controllers\ShopController::class, 'feature');

    // --- Livreur ---
    $router->post('/api/livreur/register', App\Controllers\LivreurController::class, 'register');
    $router->get('/api/livreur/profile', App\Controllers\LivreurController::class, 'profile');
    $router->put('/api/livreur/profile', App\Controllers\LivreurController::class, 'updateProfile');
    $router->put('/api/livreur/availability', App\Controllers\LivreurController::class, 'toggleAvailability');
    $router->post('/api/livreur/location', App\Controllers\LivreurController::class, 'updateLocation');
    $router->get('/api/livreur/nearby', App\Controllers\LivreurController::class, 'nearby');
    $router->put('/api/livreur/{id}/approve', App\Controllers\LivreurController::class, 'approve');

    // --- Livreur Ratings ---
    $router->get('/api/livreur/{livreur_id}/ratings', App\Controllers\RatingController::class, 'livreurRatings');

    // --- Push Notifications (PWA) ---
    $router->post('/api/push/subscribe', App\Controllers\PushController::class, 'subscribe');
    $router->delete('/api/push/unsubscribe', App\Controllers\PushController::class, 'unsubscribe');

    // --- Support Tickets ---
    $router->post('/api/support/tickets', App\Controllers\SupportController::class, 'store');
    $router->get('/api/support/tickets', App\Controllers\SupportController::class, 'index');
    $router->get('/api/support/tickets/{reference}', App\Controllers\SupportController::class, 'show');
    $router->post('/api/support/tickets/{reference}/messages', App\Controllers\SupportController::class, 'addMessage');
    $router->post('/api/support/tickets/{reference}/media',    App\Controllers\SupportController::class, 'uploadMedia');

    // --- Admin: Support ---
    $router->get('/api/admin/support/tickets', App\Controllers\SupportController::class, 'adminIndex');
    $router->put('/api/admin/support/tickets/{reference}/status', App\Controllers\SupportController::class, 'updateStatus');
    $router->put('/api/admin/support/tickets/{reference}/assign', App\Controllers\SupportController::class, 'assign');

    // --- Wallet ---
    $router->get('/api/wallet', App\Controllers\WalletController::class, 'balance');
    $router->get('/api/wallet/transactions', App\Controllers\WalletController::class, 'transactions');

    // --- Admin: Wallet management ---
    $router->post('/api/admin/wallet/top-up', App\Controllers\WalletController::class, 'topUp');
    $router->get('/api/admin/wallet/{user_id}', App\Controllers\WalletController::class, 'adminShow');

    // --- Notifications ---
    $router->get('/api/notifications', App\Controllers\NotificationController::class, 'index');
    $router->put('/api/notifications/{id}/read', App\Controllers\NotificationController::class, 'markRead');
    $router->put('/api/notifications/read-all', App\Controllers\NotificationController::class, 'markAllRead');

    // --- Pricing (admin) ---
    $router->post('/api/pricing', App\Controllers\PricingController::class, 'store');
    $router->put('/api/pricing/{id}', App\Controllers\PricingController::class, 'update');

    // --- Settings (admin) ---
    $router->get('/api/settings', App\Controllers\SettingsController::class, 'index');
    $router->get('/api/settings/categories', App\Controllers\SettingsController::class, 'categories');
    $router->post('/api/settings', App\Controllers\SettingsController::class, 'store');
    $router->put('/api/settings/bulk', App\Controllers\SettingsController::class, 'bulkUpdate');
    $router->put('/api/settings/{key}', App\Controllers\SettingsController::class, 'update');
    $router->delete('/api/settings/{key}', App\Controllers\SettingsController::class, 'destroy');

    // --- Promotions ---
    $router->get('/api/promotions', App\Controllers\PromotionController::class, 'index');
    $router->post('/api/promotions', App\Controllers\PromotionController::class, 'store');
    $router->post('/api/promotions/validate', App\Controllers\PromotionController::class, 'validateCode');
    $router->get('/api/promotions/{id}', App\Controllers\PromotionController::class, 'show');
    $router->put('/api/promotions/{id}', App\Controllers\PromotionController::class, 'update');
    $router->delete('/api/promotions/{id}', App\Controllers\PromotionController::class, 'destroy');

    // --- Banners (admin) ---
    $router->get('/api/admin/banners', App\Controllers\BannerController::class, 'adminIndex');
    $router->post('/api/admin/banners', App\Controllers\BannerController::class, 'store');
    $router->put('/api/admin/banners/reorder', App\Controllers\BannerController::class, 'reorder');
    $router->get('/api/admin/banners/{id}', App\Controllers\BannerController::class, 'show');
    $router->put('/api/admin/banners/{id}', App\Controllers\BannerController::class, 'update');
    $router->delete('/api/admin/banners/{id}', App\Controllers\BannerController::class, 'destroy');
    $router->post('/api/admin/banners/{id}/upload', App\Controllers\BannerController::class, 'uploadImage');

    // --- Maps Cache (admin) ---
    $router->get('/api/admin/maps/cache-stats', App\Controllers\MapsController::class, 'cacheStats');
    $router->post('/api/admin/maps/cache-purge', App\Controllers\MapsController::class, 'cachePurge');

    // --- Developer API Key Management ---
    $router->get('/api/developer/api-keys', App\Controllers\DeveloperController::class, 'listApiKeys');
    $router->post('/api/developer/api-keys', App\Controllers\DeveloperController::class, 'createApiKey');
    $router->put('/api/developer/api-keys/{id}', App\Controllers\DeveloperController::class, 'updateApiKey');
    $router->post('/api/developer/api-keys/{id}/regenerate-secret', App\Controllers\DeveloperController::class, 'regenerateSecret');
    $router->delete('/api/developer/api-keys/{id}', App\Controllers\DeveloperController::class, 'deleteApiKey');
    $router->get('/api/developer/api-keys/{id}/stats', App\Controllers\DeveloperController::class, 'apiKeyStats');
});


// =============================================
// DEVELOPER API ROUTES — Require API Key (X-Api-Key + X-Api-Secret)
// =============================================

$router->group('', [ApiAuth::class], function ($router) {

    // --- Orders ---
    $router->post('/api/v1/orders', App\Controllers\DeveloperController::class, 'createOrder');
    $router->get('/api/v1/orders', App\Controllers\DeveloperController::class, 'listOrders');
    $router->get('/api/v1/orders/{reference}', App\Controllers\DeveloperController::class, 'showOrder');
    $router->get('/api/v1/orders/by-reference/{reference}', App\Controllers\DeveloperController::class, 'showOrderByReference');
    $router->put('/api/v1/orders/{reference}/cancel', App\Controllers\DeveloperController::class, 'cancelOrder');
    $router->get('/api/v1/orders/{reference}/tracking', App\Controllers\DeveloperController::class, 'trackOrder');

    // --- Estimate ---
    $router->get('/api/v1/estimate', App\Controllers\DeveloperController::class, 'estimate');

    // --- Reference data ---
    $router->get('/api/v1/shops', App\Controllers\DeveloperController::class, 'listShops');
    $router->get('/api/v1/shops/{id}', App\Controllers\DeveloperController::class, 'showShop');
    $router->get('/api/v1/countries', App\Controllers\DeveloperController::class, 'countries');
    $router->get('/api/v1/pricing', App\Controllers\DeveloperController::class, 'pricing');
});
