<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Illuminate\Support\Facades\DB;
use Exception;


class ProductController extends Controller
{
    /**
     * Retrieve a list of products for the authenticated user's shop.
     */
    public function index()
    {
        $user = Auth::user();
        $shop = $user->seller->shop;

        $products = $shop->products()->with('shop', 'images')->get();

        return $products;
    }

    /**
     * Store a newly created product with all attributes, including images.
     */
    public function store(Request $request)
    {
        DB::beginTransaction();
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'brand' => 'nullable|string|max:255',
                'category' => 'required|string|max:255',
                'currency' => 'required|string|max:10',
                'subcategory' => 'nullable|string|max:255',
                'description' => 'nullable|string',
                'price' => 'required|numeric|min:0',
                'stock_quantity' => 'required|integer|min:0',
                'installment_count' => 'nullable|integer|min:1',
                'images' => 'nullable|array',
                'images.*' => 'image|mimes:jpeg,png,jpg,gif|max:2048',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $user = Auth::user();
            $shop = $user->seller->shop;

            $productCode = $request->input('product_code') ?? uniqid('product_');

            $product = $shop->products()->create(array_merge($request->only([
                'name',
                'brand',
                'category',
                'subcategory',
                'slug',
                'description',
                'price',
                'currency',
                'stock_quantity',
                'installment_count'
            ]), [
                'product_code' => $productCode,
            ]));

            if ($request->hasFile('images')) {
                // Destination path within public directory
                $destinationPath = 'uploads/products';

                // Create directory if it doesn't exist
                if (!file_exists(public_path($destinationPath))) {
                    mkdir(public_path($destinationPath), 0755, true);
                }

                foreach ($request->file('images') as $imageFile) {
                    // Generate a unique filename
                    $fileName = uniqid() . '_' . time() . '.' . $imageFile->getClientOriginalExtension();

                    // Move the uploaded file to the public directory
                    $imageFile->move(public_path($destinationPath), $fileName);

                    // Generate the URL for direct access
                    $imageUrl = url($destinationPath . '/' . $fileName);

                    $product->images()->create([
                        'image_url' => $imageUrl,
                        'width' => getimagesize($imageFile->getPathname())[0],
                        'height' => getimagesize($imageFile->getPathname())[1],
                    ]);
                }
            }

            DB::commit();

            return response()->json($product->load('shop', 'images'), 201);
        } catch (Exception $e) {
            DB::rollBack();

            return response()->json(['error' => 'An error occurred, please try again.', 'details' => $e->getMessage()], 500);
        }
    }

    /**
     * Update a product with all attributes, including images.
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'brand' => 'nullable|string|max:255',
            'category' => 'required|string|max:255',
            'description' => 'nullable|string',
            'currency' => 'required|string|max:3',
            'price' => 'required|numeric|min:0',
            'rating' => 'nullable|numeric|min:0|max:5',
            'visit' => 'nullable|integer|min:0',
            'stock_quantity' => 'required|integer|min:0',
            'images' => 'nullable|array',
            'images.*' => 'image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {
            $product = Product::findOrFail($id);
            $product->update($request->only([
                'name',
                'brand',
                'slug',
                'category',
                'subcategory',
                'description',
                'currency',
                'price',
                'rating',
                'visit',
                'stock_quantity',
            ]));

            // Process new images if provided
            if ($request->hasFile('images')) {
                // Destination path within public directory
                $destinationPath = 'uploads/products';

                // Create directory if it doesn't exist
                if (!file_exists(public_path($destinationPath))) {
                    mkdir(public_path($destinationPath), 0755, true);
                }

                foreach ($request->file('images') as $imageFile) {
                    // Generate a unique filename
                    $fileName = uniqid() . '_' . time() . '.' . $imageFile->getClientOriginalExtension();

                    // Move the uploaded file to the public directory
                    $imageFile->move(public_path($destinationPath), $fileName);

                    // Generate the URL for direct access
                    $imageUrl = url($destinationPath . '/' . $fileName);

                    $product->images()->create([
                        'image_url' => $imageUrl,
                        'width' => getimagesize($imageFile->getPathname())[0],
                        'height' => getimagesize($imageFile->getPathname())[1],
                    ]);
                }
            }

            DB::commit();
            return response()->json($product->load('shop', 'images'), 200);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'An error occurred, please try again.', 'details' => $e->getMessage()], 500);
        }
    }

    /**
     * Show a specific product by ID, including shop and images.
     */
    public function show($id)
    {
        $product = Product::with('shop', 'images')->findOrFail($id);
        return response()->json($product, 200);
    }

    /**
     * Retrieve recent products with shop and images.
     */
    public function recentProducts()
    {
        $products = Product::with('shop', 'images')
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get();

        return $products;
    }

    /**
     * Retrieve related products based on the category, excluding the current product, including shop and images.
     */
    public function relatedProducts($id)
    {
        $product = Product::findOrFail($id);
        $relatedProducts = Product::with('shop', 'images')
            ->where('category', $product->category)
            ->where('id', '!=', $product->id)
            ->take(10)
            ->get();

        return $relatedProducts;
    }

    /**
     * Increment the views of a product.
     */
    public function incrementViews(Product $product)
    {
        $product->increment('visit');
        return response()->json($product, 200);
    }

    /**
     * Delete a product by ID.
     */
    public function destroy($id)
    {
        DB::beginTransaction();
        try {
            $product = Product::with('images')->findOrFail($id);

            // Delete all associated images from storage
            foreach ($product->images as $image) {
                // Get image path relative to the public directory
                $path = str_replace(url('/'), '', $image->image_url);
                $path = ltrim($path, '/');

                // Delete from public storage
                if (file_exists(public_path($path))) {
                    unlink(public_path($path));
                }

                // Delete the image record
                $image->delete();
            }

            // Delete the product
            $product->delete();

            DB::commit();
            return response()->json(['message' => 'Product deleted successfully'], 200);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'An error occurred while deleting the product.', 'details' => $e->getMessage()], 500);
        }
    }

    /**
     * Display a listing of the resource.
     */
    public function search(Request $request)
    {
        $keyword = $request->keyword;
        $category = $request->category;
        $page = $request->page ?? 1;
        $limit = $request->limit ?? 10;

        $query = Product::query();

        if ($keyword) {
            $query->where(function ($q) use ($keyword) {
                $q->where('products.name', 'LIKE', '%' . $keyword . '%')
                    ->orWhere('products.category', 'LIKE', '%' . $keyword . '%')
                    ->orWhereHas('shop', function ($q) use ($keyword) {
                        $q->where('shops.name', 'LIKE', '%' . $keyword . '%');
                    });
            });
        }

        if ($category) {
            $query->where('category', $category);
        }

        $query->with(['shop', 'images'])
            ->orderBy('id', 'desc');

        $results = $query->paginate($limit, ['*'], 'page', $page);

        return response()->json($results, 200);
    }

    /**
     * Retrieve all products for a specific shop.
     */
    public function getProductsByShop($shopId)
    {
        $products = Product::with('shop', 'images')
            ->where('shop_id', $shopId)
            ->get();

        return response()->json($products, 200);
    }

    /**
     * Retrieve all products for admin with filtering options.
     */
    public function getAdminProducts(Request $request)
    {
        $search = $request->input('search');
        $page = $request->input('page', 1);
        $pageSize = $request->input('pageSize', 10);

        $query = Product::query();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', '%' . $search . '%')
                    ->orWhere('category', 'LIKE', '%' . $search . '%')
                    ->orWhere('brand', 'LIKE', '%' . $search . '%');
            });
        }

        $products = $query->with(['shop', 'images'])
            ->orderBy('created_at', 'desc')
            ->paginate($pageSize, ['*'], 'page', $page);

        return response()->json($products, 200);
    }

    /**
     * Toggle the activation status of a product (activate or deactivate).
     */
    public function toggleActive($id)
    {
        $user = Auth::user();

        if (!$user->isAdmin()) {
            return response()->json([
                'message' => 'You do not have permission to perform this action.'
            ], 403);
        }

        $product = Product::findOrFail($id);

        $product->is_active = !$product->is_active;

        $product->save();

        return $product;
    }

    /**
     * Find a product by its product code.
     */
    public function findByProductCode($code)
    {
        $product = Product::with('shop', 'images')->where('product_code', $code)->firstOrFail();
        return response()->json($product, 200);
    }

    /**
     * Upload a single product image.
     */
    public function uploadImage(Request $request, $productId)
    {
        $validator = Validator::make($request->all(), [
            'image' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $product = Product::findOrFail($productId);

        // Destination path within public directory
        $destinationPath = 'uploads/products';

        // Create directory if it doesn't exist
        if (!file_exists(public_path($destinationPath))) {
            mkdir(public_path($destinationPath), 0755, true);
        }

        // Generate a unique filename
        $fileName = uniqid() . '_' . time() . '.' . $request->file('image')->getClientOriginalExtension();

        // Move the uploaded file to the public directory
        $request->file('image')->move(public_path($destinationPath), $fileName);

        // Generate the URL for direct access
        $imageUrl = url($destinationPath . '/' . $fileName);

        $image = $product->images()->create([
            'image_url' => $imageUrl,
            'width' => getimagesize($request->file('image')->getPathname())[0],
            'height' => getimagesize($request->file('image')->getPathname())[1],
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $image
        ], 201);
    }

    /**
     * Delete a product image.
     */
    public function deleteImage($productId, $imageId)
    {
        $product = Product::findOrFail($productId);

        $image = $product->images()->findOrFail($imageId);

        // Get image path relative to the public directory
        $path = str_replace(url('/'), '', $image->image_url);
        $path = ltrim($path, '/');

        // Delete from public storage if file exists
        if (file_exists(public_path($path))) {
            unlink(public_path($path));
        }

        // Delete the image record
        $image->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Image deleted successfully'
        ]);
    }
}