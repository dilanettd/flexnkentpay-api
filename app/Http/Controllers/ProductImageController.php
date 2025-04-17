<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Models\ProductImage;
use Illuminate\Support\Facades\Storage;

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

        foreach ($request->file('images') as $image) {
            $extension = $image->getClientOriginalExtension();
            $imageName = 'images/products/' . uniqid() . '.' . $extension;

            // Store image on S3
            Storage::disk('s3')->put($imageName, file_get_contents($image));

            // Retrieve and save the public URL
            $publicUrl = Storage::disk('s3')->url($imageName);

            // Create a new ProductImage entry
            ProductImage::create([
                "image_url" => $publicUrl,
                'width' => getimagesize($image)[0],
                'height' => getimagesize($image)[1],
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
    public function show($path)
    {
        if (Storage::disk('s3')->exists($path)) {
            $content = Storage::disk('s3')->get($path);
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
        $urlParts = explode('/', $imageProduct->image_url);
        $filename = end($urlParts);

        Storage::disk('s3')->delete('images/products/' . $filename);
        $imageProduct->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Image deleted successfully'
        ], 200);
    }
}
