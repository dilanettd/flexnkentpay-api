<?php

namespace App\Http\Controllers;

use App\Models\ProductReview;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProductReviewController extends Controller
{
    public function store(Request $request)
    {
        $user = Auth::user();

        // Validate the incoming request data
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'rating' => 'required|integer|min:1|max:5',
            'review' => 'required|string',
        ]);

        // Check if the user has already left a review for this product
        $existingReview = ProductReview::where('user_id', $user->id)
            ->where('product_id', $validated['product_id'])
            ->first();
        if ($existingReview) {
            return response()->json(['message' => 'You have already left a review for this product.'], 400);
        }

        // Create the new product review
        $review = ProductReview::create([
            'user_id' => $user->id,
            'product_id' => $validated['product_id'],
            'rating' => $validated['rating'],
            'review' => $validated['review'],
        ]);

        // Update the product's average rating after a new review is added
        $this->updateProductRating($validated['product_id']);

        // Return the review with user data
        return response()->json($review->load('user'), 201);
    }

    public function index($productId)
    {
        // Get all reviews for a specific product, including the associated user
        $reviews = ProductReview::with('user')->where('product_id', $productId)->get();
        return response()->json($reviews);
    }

    /**
     * Update the product's rating after a new review is added.
     *
     * @param int $productId
     */
    private function updateProductRating($productId)
    {
        // Calculate the average rating of the product based on all its reviews
        $product = Product::find($productId);
        $averageRating = ProductReview::where('product_id', $productId)->avg('rating');

        // Update the product's rating field
        $product->rating = round($averageRating, 1);
        $product->save();
    }
}


