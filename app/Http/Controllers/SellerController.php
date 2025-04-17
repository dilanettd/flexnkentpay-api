<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use App\Models\Seller;

class SellerController extends Controller
{
    public function getSellerDetails()
    {
        $user = Auth::user();
        $seller = Seller::with('shop')->where('user_id', $user->id)->first();

        if (!$seller) {
            return response()->json(['message' => 'Seller not found'], 404);
        }

        return response()->json(['seller' => $seller, 'shop' => $seller->shop]);
    }
}
