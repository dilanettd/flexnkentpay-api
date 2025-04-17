<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;
use App\Models\Seller;
use App\Models\Shop;

class ShopController extends Controller
{
    public function updateDetails(Request $request)
    {
        $user = Auth::user();

        $seller = Seller::firstOrCreate(['user_id' => $user->id]);

        $user->role = 'seller';
        $user->save();

        $request->validate([
            'name' => 'required|string|max:255',
            'contact_number' => 'sometimes|string|max:15|nullable',
            'description' => 'sometimes|string|nullable',
            'website_url' => 'sometimes|url|nullable',
            'location' => 'sometimes|string|nullable',
        ]);

        if (!$seller->shop) {
            $shopData = $request->only([
                'name',
                'contact_number',
                'description',
                'website_url',
                'location'
            ]);

            $shop = $seller->shop()->create($shopData);
        } else {
            $shop = $seller->shop;
            $shop->update($request->only([
                'name',
                'contact_number',
                'description',
                'website_url',
                'location'
            ]));
        }

        return response()->json($user->load('seller.shop'));
    }


    public function getShopById($id)
    {
        $shop = Shop::find($id);

        if (!$shop) {
            return response()->json(['message' => 'Shop not found'], 404);
        }
        return response()->json($shop);
    }


    public function updateLogo(Request $request)
    {
        $user = Auth::user();
        $seller = Seller::where('user_id', $user->id)->first();

        if (!$seller) {
            return response()->json(['message' => 'Seller not found'], 404);
        }

        $shop = Shop::where('seller_id', $seller->id)->first();
        if (!$shop) {
            return response()->json(['message' => 'Shop not found'], 404);
        }

        $request->validate([
            'logo' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        if ($request->hasFile('logo')) {
            if ($shop->logo_url) {
                $urlParts = explode('/', $shop->logo_url);
                $filename = end($urlParts);
                Storage::disk('s3')->delete('images/logos/' . $filename);
            }

            $image = $request->file('logo');
            $extension = $image->getClientOriginalExtension();
            $imageName = 'images/logos/' . uniqid() . "." . $extension;
            Storage::disk('s3')->put($imageName, file_get_contents($image));

            $shop->logo_url = Storage::disk('s3')->url($imageName);
            Storage::disk('s3')->setVisibility($shop->logo_url, 'public');
            $shop->save();


            return response()->json($user->load('seller.shop'));
        }

        return response()->json(['message' => 'Logo update failed'], 400);
    }

    public function updateCoverImage(Request $request)
    {
        $user = Auth::user();
        $seller = Seller::where('user_id', $user->id)->first();

        if (!$seller) {
            return response()->json(['message' => 'Seller not found'], 404);
        }

        $shop = Shop::where('seller_id', $seller->id)->first();
        if (!$shop) {
            return response()->json(['message' => 'Shop not found'], 404);
        }

        $request->validate([
            'cover_image' => 'required|image|mimes:jpeg,png,jpg,gif|max:4096',
        ]);

        if ($request->hasFile('cover_image')) {
            if ($shop->cover_photo_url) {
                $urlParts = explode('/', $shop->cover_photo_url);
                $filename = end($urlParts);
                Storage::disk('s3')->delete('images/covers/' . $filename);
            }

            $image = $request->file('cover_image');
            $extension = $image->getClientOriginalExtension();
            $imageName = 'images/covers/' . uniqid() . "." . $extension;
            Storage::disk('s3')->put($imageName, file_get_contents($image));

            $shop->cover_photo_url = Storage::disk('s3')->url($imageName);
            Storage::disk('s3')->setVisibility($shop->cover_photo_url, 'public');
            $shop->save();

            return response()->json($user->load('seller.shop'));
        }

        return response()->json(['message' => 'Cover image update failed'], 400);
    }

    public function getTopRatedShops(Request $request)
    {
        $topRatedShops = Shop::whereNotNull('rating')
            ->orderBy('rating', 'desc')
            ->take(10)
            ->get();

        return response()->json($topRatedShops);
    }

    /**
     * Increment the shop's visit count.
     *
     * @param int $shopId
     * @return \Illuminate\Http\JsonResponse
     */
    public function incrementVisitCount($shopId)
    {
        // Find the shop by its ID
        $shop = Shop::findOrFail($shopId);

        // Increment the visit count by 1
        $shop->incrementVisitCount();

        // Return a success response
        return response()->json([
            'message' => 'Visit count updated successfully.',
            'shop' => $shop
        ]);
    }


}