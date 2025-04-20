<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    /**
     * Récupère toutes les catégories.
     */
    public function index()
    {
        return response()->json(Category::all(), 200);
    }

    /**
     * Crée une nouvelle catégorie.
     */
    public function store(Request $request)
    {
        if (!Auth::user() || !Auth::user()->isAdmin()) {
            return response()->json(['message' => 'Accès non autorisé'], 403);
        }

        $request->validate([
            'name' => 'required|string|unique:categories,name|max:255',
            'description' => 'nullable|string',
        ]);

        $category = Category::create([
            'name' => $request->name,
            'description' => $request->description,
        ]);

        return response()->json([
            'message' => 'Catégorie créée avec succès',
            'category' => $category,
        ], 201);
    }

    /**
     * Affiche une catégorie spécifique.
     */
    public function show($id)
    {
        $category = Category::findOrFail($id);

        return response()->json($category, 200);
    }

    /**
     * Met à jour une catégorie.
     */
    public function update(Request $request, $id)
    {
        if (!Auth::user() || !Auth::user()->isAdmin()) {
            return response()->json(['message' => 'Accès non autorisé'], 403);
        }

        $category = Category::findOrFail($id);

        $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('categories')->ignore($category->id),
            ],
            'description' => 'nullable|string',
        ]);

        $category->update($request->only(['name', 'description']));

        return response()->json([
            'message' => 'Catégorie mise à jour avec succès',
            'category' => $category,
        ]);
    }

    /**
     * Supprime une catégorie.
     */
    public function destroy($id)
    {
        if (!Auth::user() || !Auth::user()->isAdmin()) {
            return response()->json(['message' => 'Accès non autorisé'], 403);
        }

        $category = Category::findOrFail($id);
        $category->delete();

        return response()->json([
            'message' => 'Catégorie supprimée avec succès',
        ], 200);
    }
}
