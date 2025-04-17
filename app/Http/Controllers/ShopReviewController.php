<?php

namespace App\Http\Controllers;

use App\Models\ShopReview;
use App\Models\Shop;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ShopReviewController extends Controller
{
    public function store(Request $request)
    {
        $user = Auth::user();

        // Validate the incoming request data
        $validated = $request->validate([
            'shop_id' => 'required|exists:shops,id',
            'rating' => 'required|integer|min:1|max:5',
            'review' => 'required|string',
        ]);

        // Check if the user has already left a review for this shop
        $existingReview = ShopReview::where('user_id', $user->id)
            ->where('shop_id', $validated['shop_id'])
            ->first();
        if ($existingReview) {
            return response()->json(['message' => 'You have already left a review for this shop.'], 400);
        }

        // Create the new shop review
        $review = ShopReview::create([
            'user_id' => $user->id,
            'shop_id' => $validated['shop_id'],
            'rating' => $validated['rating'],
            'review' => $validated['review'],
        ]);

        // Update the shop's average rating after a new review is added
        $this->updateShopRating($validated['shop_id']);

        // Return the review with user data
        return response()->json(['message' => 'Review added successfully.', 'review' => $review->load('user')], 201);
    }

    public function index($shopId)
    {
        // Get all reviews for a specific shop, including the associated user data
        $reviews = ShopReview::with('user')->where('shop_id', $shopId)->get();
        return response()->json($reviews);
    }

    /**
     * Update the shop's rating after a new review is added.
     *
     * @param int $shopId
     */
    private function updateShopRating($shopId)
    {
        // Calculate the average rating of the shop based on all its reviews
        $shop = Shop::find($shopId);
        $averageRating = ShopReview::where('shop_id', $shopId)->avg('rating');

        // Update the shop's rating field
        $shop->rating = round($averageRating, 1);
        $shop->save();
    }
}

