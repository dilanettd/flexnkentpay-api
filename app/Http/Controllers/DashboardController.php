<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Seller;
use App\Models\Shop;
use App\Models\Product;
use App\Models\MomoTransaction;
use App\Models\OrderPayment;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    /**
     * Get dashboard statistics.
     */
    public function getDashboardStats(): JsonResponse
    {
        // Calculer les statistiques
        $totalUsers = User::count();
        $activeUsers = User::where('is_active', true)->count();
        $totalVendors = Seller::count();
        $totalShops = Shop::count();
        $totalProducts = Product::count();
        $momoVolume = MomoTransaction::where('status', 'success')->sum('amount');
        $momoTransactions = MomoTransaction::count();
        $successfulPayments = OrderPayment::where('status', 'success')->count();
        $pendingPayments = OrderPayment::where('status', 'pending')->count();

        $dashboardCards = [
            [
                'title' => 'Total Users',
                'value' => number_format($totalUsers),
                'icon' => 'bi-people-fill',
                'trend' => 12,
                'bgColor' => 'bg-blue-100',
            ],
            [
                'title' => 'Active Users',
                'value' => number_format($activeUsers),
                'icon' => 'bi-person-check-fill',
                'trend' => 8,
                'bgColor' => 'bg-green-100',
            ],
            [
                'title' => 'Total Vendors',
                'value' => number_format($totalVendors),
                'icon' => 'bi-shop',
                'trend' => 5,
                'bgColor' => 'bg-purple-100',
            ],
            [
                'title' => 'Total Shops',
                'value' => number_format($totalShops),
                'icon' => 'bi-shop-window',
                'trend' => 5,
                'bgColor' => 'bg-pink-100',
            ],
            [
                'title' => 'Total Products',
                'value' => number_format($totalProducts),
                'icon' => 'bi-box-seam-fill',
                'trend' => 15,
                'bgColor' => 'bg-orange-100',
            ],
            [
                'title' => 'Mobile Money Volume',
                'value' => number_format($momoVolume, 2) . ' XAF',
                'icon' => 'bi-phone-fill',
                'trend' => 18,
                'bgColor' => 'bg-yellow-100',
            ],
            [
                'title' => 'Mobile Money Transactions',
                'value' => number_format($momoTransactions),
                'icon' => 'bi-credit-card-2-front-fill',
                'trend' => 10,
                'bgColor' => 'bg-indigo-100',
            ],
            [
                'title' => 'Successful Payments',
                'value' => number_format($successfulPayments),
                'icon' => 'bi-check-circle-fill',
                'trend' => 7,
                'bgColor' => 'bg-green-100',
            ],
            [
                'title' => 'Pending Payments',
                'value' => number_format($pendingPayments),
                'icon' => 'bi-clock-fill',
                'trend' => -3,
                'bgColor' => 'bg-red-100',
            ],
        ];

        return response()->json($dashboardCards);
    }
}
