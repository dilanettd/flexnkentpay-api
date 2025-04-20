<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Models\ProductImage;

class ProductImageController extends Controller
{
    /**
     * Retrieve all product images.
     */
    public function index()
    {
        return ProductImage::all();
    }

    /**
     * Store new product images without adding watermark.
     */
    public function store(Request $request)
    {
        // Validate that images is an array of uploaded files
        $request->validate([
            'images' => 'required|array',
            'images.*' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
            'product_id' => 'required|exists:products,id',
        ]);

        // Destination path within public directory
        $destinationPath = 'uploads/products';

        // Create directory if it doesn't exist
        if (!file_exists(public_path($destinationPath))) {
            mkdir(public_path($destinationPath), 0755, true);
        }

        foreach ($request->file('images') as $image) {
            // Generate a unique filename
            $fileName = uniqid() . '_' . time() . '.' . $image->getClientOriginalExtension();

            // Move the uploaded file to the public directory
            $image->move(public_path($destinationPath), $fileName);

            // Generate the URL for direct access
            $imageUrl = url($destinationPath . '/' . $fileName);

            // Create a new ProductImage entry
            ProductImage::create([
                "image_url" => $imageUrl,
                'width' => getimagesize($image->getPathname())[0],
                'height' => getimagesize($image->getPathname())[1],
                "product_id" => $request->product_id,
            ]);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Images uploaded successfully'
        ], 201);
    }

    /**
     * Display a specific product image.
     */
    public function show($id)
    {
        $image = ProductImage::findOrFail($id);

        // Get image path relative to the public directory
        $path = str_replace(url('/'), '', $image->image_url);
        $path = ltrim($path, '/');

        if (file_exists(public_path($path))) {
            $content = file_get_contents(public_path($path));
            $extension = pathinfo($path, PATHINFO_EXTENSION);

            return (new Response($content, 200))
                ->header('Content-Type', 'image/' . $extension);
        } else {
            return response()->json([
                'status' => 'error',
                'message' => 'Image not found'
            ], 404);
        }
    }

    /**
     * Delete a specific product image.
     */
    public function destroy($id)
    {
        $imageProduct = ProductImage::findOrFail($id);

        // Get image path relative to the public directory
        $path = str_replace(url('/'), '', $imageProduct->image_url);
        $path = ltrim($path, '/');

        // Delete file from public directory if it exists
        if (file_exists(public_path($path))) {
            unlink(public_path($path));
        }

        // Delete the database record
        $imageProduct->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Image deleted successfully'
        ], 200);
    }
}