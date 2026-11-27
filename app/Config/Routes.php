<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');

/*
 * --------------------------------------------------------------------
 * Documentation (Swagger UI) — désactivée en production
 * --------------------------------------------------------------------
 */
$routes->get('docs', 'Docs::index');
$routes->get('docs/openapi.yaml', 'Docs::spec');

/*
 * --------------------------------------------------------------------
 * Images publiques (logos, couvertures, catégories, produits) — writable/uploads
 * --------------------------------------------------------------------
 */
$routes->get('media/(:any)', 'Media::show/$1');

/*
 * --------------------------------------------------------------------
 * API REST v1
 * --------------------------------------------------------------------
 * Filtres appliqués à tout le groupe : CORS + limitation des requêtes.
 * Chaque route protégée déclare explicitement ses filtres (jwt, role, restaurant).
 */
$routes->group('api/v1', ['namespace' => 'App\Controllers\API\V1', 'filter' => ['cors', 'ratelimit']], static function (RouteCollection $routes): void {
    // Requêtes préflight CORS : la réponse est produite par le filtre `cors`.
    $routes->options('(:any)', static fn () => service('response')->setStatusCode(204));

    // Public
    $routes->get('health', 'System\HealthController::index');

    // Notifications des fournisseurs de paiement (signature vérifiée ; aucun fournisseur actif → 404).
    $routes->post('webhooks/payments/(:segment)', 'System\PaymentWebhookController::receive/$1');

    /*
     * Authentification — routes publiques, limitées plus strictement (compteurs par usage et par IP).
     */
    $routes->group('auth', ['namespace' => 'App\Controllers\API\V1\Auth'], static function (RouteCollection $routes): void {
        $routes->post('register', 'AuthController::register', ['filter' => 'ratelimit:10,3600,auth-register']);
        $routes->post('login', 'AuthController::login', ['filter' => 'ratelimit:10,60,auth-login']);
        $routes->post('refresh', 'AuthController::refresh', ['filter' => 'ratelimit:30,60,auth-refresh']);
        $routes->post('forgot-password', 'AuthController::forgotPassword', ['filter' => 'ratelimit:5,900,auth-forgot']);
        $routes->post('reset-password', 'AuthController::resetPassword', ['filter' => 'ratelimit:10,900,auth-reset']);

        // Compte connecté (y compris en attente de vérification du téléphone)
        $routes->get('me', 'AuthController::me', ['filter' => 'jwt:allow-pending']);
        $routes->patch('me', 'AuthController::updateProfile', ['filter' => 'jwt:allow-pending']);
        $routes->post('logout', 'AuthController::logout', ['filter' => 'jwt:allow-pending']);
        $routes->post('verify-phone', 'AuthController::verifyPhone', ['filter' => ['jwt:allow-pending', 'ratelimit:10,900,auth-verify']]);
        $routes->post('verify-phone/resend', 'AuthController::resendPhoneCode', ['filter' => ['jwt:allow-pending', 'ratelimit:5,900,auth-resend']]);
        $routes->post('change-password', 'AuthController::changePassword', ['filter' => ['jwt:allow-pending', 'ratelimit:10,900,auth-change']]);
        $routes->get('sessions', 'AuthController::sessions', ['filter' => 'jwt:allow-pending']);
        $routes->delete('sessions/(:segment)', 'AuthController::revokeSession/$1', ['filter' => 'jwt:allow-pending']);
    });

    // Notifications in-app du compte connecté (client, propriétaire, personnel…).
    $routes->get('notifications', 'Account\NotificationController::index', ['filter' => 'jwt']);
    $routes->post('notifications/read-all', 'Account\NotificationController::readAll', ['filter' => 'jwt']);
    $routes->patch('notifications/(:segment)/read', 'Account\NotificationController::read/$1', ['filter' => 'jwt']);

    /*
     * Annuaire public des restaurants et menus (sans authentification).
     */
    $routes->get('restaurants', 'Public\RestaurantDirectoryController::index');
    $routes->get('restaurants/(:segment)', 'Public\RestaurantDirectoryController::show/$1');
    $routes->get('restaurants/(:segment)/categories', 'Public\RestaurantDirectoryController::categories/$1');
    $routes->get('restaurants/(:segment)/products', 'Public\RestaurantDirectoryController::products/$1');
    $routes->get('restaurants/(:segment)/products/(:segment)', 'Public\RestaurantDirectoryController::product/$1/$2');
    $routes->get('restaurants/(:segment)/reviews', 'Public\RestaurantDirectoryController::reviews/$1');

    // Inscription d'un restaurant par un propriétaire (compte vérifié).
    $routes->post('restaurants', 'Restaurant\RestaurantController::create', ['filter' => ['jwt', 'role:restaurant_owner']]);

    /*
     * Espace restaurant : restaurant résolu par le filtre « restaurant » (en-tête X-Restaurant-Id
     * si l'utilisateur appartient à plusieurs restaurants), permission vérifiée sur chaque route.
     */
    $routes->group('restaurant', ['namespace' => 'App\Controllers\API\V1\Restaurant'], static function (RouteCollection $routes): void {
        $can = static fn (string $permission): array => ['filter' => ['jwt', 'restaurant:' . $permission]];

        $routes->get('profile', 'RestaurantController::profile', $can('restaurant.view'));
        $routes->put('profile', 'RestaurantController::update', $can('restaurant.update'));
        $routes->patch('availability', 'RestaurantController::availability', $can('restaurant.update'));
        $routes->post('submit', 'RestaurantController::submit', $can('restaurant.settings'));
        $routes->post('logo', 'RestaurantController::uploadLogo', $can('restaurant.update'));
        $routes->post('cover', 'RestaurantController::uploadCover', $can('restaurant.update'));

        $routes->get('addresses', 'AddressController::index', $can('restaurant.view'));
        $routes->post('addresses', 'AddressController::create', $can('restaurant.update'));
        $routes->put('addresses/(:segment)', 'AddressController::update/$1', $can('restaurant.update'));
        $routes->delete('addresses/(:segment)', 'AddressController::delete/$1', $can('restaurant.update'));

        $routes->get('opening-hours', 'OpeningHoursController::index', $can('restaurant.view'));
        $routes->put('opening-hours', 'OpeningHoursController::replace', $can('restaurant.update'));

        $routes->get('staff', 'StaffController::index', $can('staff.view'));
        $routes->post('staff', 'StaffController::create', $can('staff.manage'));
        $routes->patch('staff/(:segment)', 'StaffController::update/$1', $can('staff.manage'));
        $routes->delete('staff/(:segment)', 'StaffController::delete/$1', $can('staff.manage'));

        $routes->get('categories', 'CategoryController::index', $can('catalog.view'));
        $routes->post('categories', 'CategoryController::create', $can('catalog.manage'));
        $routes->patch('categories/reorder', 'CategoryController::reorder', $can('catalog.manage'));
        $routes->put('categories/(:segment)', 'CategoryController::update/$1', $can('catalog.manage'));
        $routes->delete('categories/(:segment)', 'CategoryController::delete/$1', $can('catalog.manage'));
        $routes->post('categories/(:segment)/image', 'CategoryController::uploadImage/$1', $can('catalog.manage'));

        $routes->get('products', 'ProductController::index', $can('catalog.view'));
        $routes->post('products', 'ProductController::create', $can('catalog.manage'));
        $routes->get('products/(:segment)', 'ProductController::show/$1', $can('catalog.view'));
        $routes->put('products/(:segment)', 'ProductController::update/$1', $can('catalog.manage'));
        $routes->delete('products/(:segment)', 'ProductController::delete/$1', $can('catalog.manage'));
        $routes->patch('products/(:segment)/availability', 'ProductController::availability/$1', $can('catalog.availability'));
        $routes->post('products/(:segment)/images', 'ProductController::uploadImage/$1', $can('catalog.manage'));
        $routes->delete('products/(:segment)/images/(:segment)', 'ProductController::deleteImage/$1/$2', $can('catalog.manage'));

        $routes->get('orders', 'OrderController::index', $can('orders.view'));
        $routes->get('orders/(:segment)', 'OrderController::show/$1', $can('orders.view'));
        $routes->patch('orders/(:segment)/status', 'OrderController::status/$1', $can('orders.manage'));
        $routes->post('orders/(:segment)/cash-payment', 'OrderController::cashPayment/$1', $can('payments.record'));
        $routes->put('orders/(:segment)/delivery', 'OrderController::delivery/$1', $can('deliveries.manage'));
        $routes->post('orders/(:segment)/delivery/failure', 'OrderController::deliveryFailure/$1', $can('deliveries.manage'));
        $routes->post('orders/(:segment)/delivery/proof', 'OrderController::uploadDeliveryProof/$1', $can('deliveries.manage'));
        $routes->get('orders/(:segment)/delivery/proof', 'OrderController::deliveryProof/$1', $can('orders.view'));

        $routes->get('promotions', 'PromotionController::index', $can('promotions.manage'));
        $routes->post('promotions', 'PromotionController::create', $can('promotions.manage'));
        $routes->get('promotions/(:segment)', 'PromotionController::show/$1', $can('promotions.manage'));
        $routes->put('promotions/(:segment)', 'PromotionController::update/$1', $can('promotions.manage'));
        $routes->patch('promotions/(:segment)/status', 'PromotionController::status/$1', $can('promotions.manage'));
        $routes->delete('promotions/(:segment)', 'PromotionController::delete/$1', $can('promotions.manage'));

        $routes->get('reviews', 'ReviewController::index', $can('restaurant.view'));

        $routes->get('notifications', 'NotificationController::index', $can('orders.view'));
        $routes->post('notifications/read-all', 'NotificationController::readAll', $can('orders.view'));
        $routes->patch('notifications/(:segment)/read', 'NotificationController::read/$1', $can('orders.view'));

        $routes->get('finance', 'FinanceController::balances', $can('finance.view'));
        $routes->get('finance/ledger', 'FinanceController::ledger', $can('finance.view'));
    });

    /*
     * Espace client : compte vérifié avec le rôle « customer ». Toute donnée est limitée au client connecté.
     */
    $routes->group('customer', ['namespace' => 'App\Controllers\API\V1\Customer'], static function (RouteCollection $routes): void {
        $customer = ['filter' => ['jwt', 'role:customer']];

        $routes->get('addresses', 'AddressController::index', $customer);
        $routes->post('addresses', 'AddressController::create', $customer);
        $routes->get('addresses/(:segment)', 'AddressController::show/$1', $customer);
        $routes->put('addresses/(:segment)', 'AddressController::update/$1', $customer);
        $routes->delete('addresses/(:segment)', 'AddressController::delete/$1', $customer);

        $routes->get('favorites', 'FavoriteController::index', $customer);
        $routes->put('favorites/(:segment)', 'FavoriteController::add/$1', $customer);
        $routes->delete('favorites/(:segment)', 'FavoriteController::remove/$1', $customer);

        $routes->post('orders/quote', 'OrderController::quote', ['filter' => ['jwt', 'role:customer', 'ratelimit:60,60,order-quote,per-user']]);
        $routes->post('orders', 'OrderController::create', ['filter' => ['jwt', 'role:customer', 'ratelimit:20,600,order-create,per-user']]);
        $routes->get('orders', 'OrderController::index', $customer);
        $routes->get('orders/(:segment)', 'OrderController::show/$1', $customer);
        $routes->post('orders/(:segment)/cancel', 'OrderController::cancel/$1', $customer);
        $routes->post('orders/(:segment)/review', 'ReviewController::create/$1', ['filter' => ['jwt', 'role:customer', 'ratelimit:20,3600,review-create,per-user']]);
        $routes->get('reviews', 'ReviewController::index', $customer);
    });

    /*
     * Administration WapiFood (super_admin uniquement).
     */
    $routes->group('admin', ['namespace' => 'App\Controllers\API\V1\Admin'], static function (RouteCollection $routes): void {
        $admin = ['filter' => ['jwt', 'role:super_admin']];

        $routes->get('restaurants', 'RestaurantAdminController::index', $admin);
        $routes->get('restaurants/(:segment)', 'RestaurantAdminController::show/$1', $admin);
        $routes->patch('restaurants/(:segment)/approve', 'RestaurantAdminController::approve/$1', $admin);
        $routes->patch('restaurants/(:segment)/reject', 'RestaurantAdminController::reject/$1', $admin);
        $routes->patch('restaurants/(:segment)/suspend', 'RestaurantAdminController::suspend/$1', $admin);
        $routes->patch('restaurants/(:segment)/reactivate', 'RestaurantAdminController::reactivate/$1', $admin);
        $routes->patch('restaurants/(:segment)/commission', 'RestaurantAdminController::commission/$1', $admin);

        $routes->get('finance/balances', 'FinanceAdminController::balances', $admin);
        $routes->get('restaurants/(:segment)/ledger', 'FinanceAdminController::ledger/$1', $admin);
        $routes->post('restaurants/(:segment)/settlements', 'FinanceAdminController::settlement/$1', $admin);
        $routes->post('restaurants/(:segment)/adjustments', 'FinanceAdminController::adjustment/$1', $admin);
        $routes->get('orders/(:segment)', 'FinanceAdminController::order/$1', $admin);
        $routes->post('orders/(:segment)/refunds', 'FinanceAdminController::refund/$1', $admin);
        $routes->get('orders/(:segment)/delivery/proof', 'FinanceAdminController::deliveryProof/$1', $admin);

        $routes->get('promotions', 'PromotionAdminController::index', $admin);
        $routes->post('promotions', 'PromotionAdminController::create', $admin);
        $routes->get('promotions/(:segment)', 'PromotionAdminController::show/$1', $admin);
        $routes->put('promotions/(:segment)', 'PromotionAdminController::update/$1', $admin);
        $routes->patch('promotions/(:segment)/status', 'PromotionAdminController::status/$1', $admin);
        $routes->delete('promotions/(:segment)', 'PromotionAdminController::delete/$1', $admin);

        $routes->get('reviews', 'ReviewAdminController::index', $admin);
        $routes->patch('reviews/(:segment)/moderation', 'ReviewAdminController::moderate/$1', $admin);

        $routes->get('notifications', 'NotificationAdminController::index', $admin);
        $routes->get('notifications/(:segment)', 'NotificationAdminController::show/$1', $admin);
        $routes->post('notifications/(:segment)/retry', 'NotificationAdminController::retry/$1', $admin);
    });
});
