<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Fee;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class FeeController extends Controller
{
    /**
     * Displays all fees.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        if (!Auth::user()->isAdmin()) {
            return response()->json(['message' => 'Accès non autorisé'], 403);
        }

        $fees = Fee::all();

        return response()->json($fees);
    }

    /**
     * Creates a new fee.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        if (!Auth::user()->isAdmin()) {
            return response()->json(['message' => 'Accès non autorisé'], 403);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'type' => ['required', 'string', Rule::in(['order', 'penalty'])],
            'percentage' => 'required|numeric|min:0|max:100',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($request->is_active ?? true) {
            Fee::where('type', $request->type)
                ->where('is_active', true)
                ->update(['is_active' => false]);
        }

        $fee = Fee::create([
            'name' => $request->name,
            'type' => $request->type,
            'percentage' => $request->percentage,
            'is_active' => $request->is_active ?? true,
        ]);

        return response()->json([
            'message' => 'Frais créé avec succès',
            'fee' => $fee
        ], 201);
    }

    /**
     * Displays a specific fee.
     *
     * @param string $type
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($type)
    {
        if (!Auth::user()) {
            return response()->json(['message' => 'Accès non autorisé'], 403);
        }

        $fee = Fee::where('type', $type)->first();

        return response()->json($fee);
    }

    /**
     * Updates an existing fee.
     *
     * @param Request $request
     * @param string $type
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $type)
    {
        if (!Auth::user()->isAdmin()) {
            return response()->json(['message' => 'Accès non autorisé'], 403);
        }

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'type' => ['sometimes', 'string', Rule::in(['order', 'penalty'])],
            'percentage' => 'sometimes|numeric|min:0|max:100',
            'is_active' => 'sometimes|boolean',
        ]);

        $fee = Fee::where('type', $type)->firstOrFail();

        if (
            ($request->has('type') && $request->type !== $fee->type) ||
            ($request->has('is_active') && $request->is_active && !$fee->is_active)
        ) {
            $newType = $request->type ?? $fee->type;

            if ($request->is_active ?? $fee->is_active) {
                Fee::where('type', $newType)
                    ->where('type', '!=', $type)
                    ->where('is_active', true)
                    ->update(['is_active' => false]);
            }
        }

        $fee->update($request->only(['name', 'type', 'percentage', 'is_active']));

        return response()->json([
            'message' => 'Frais mis à jour avec succès',
            'fee' => $fee
        ]);
    }

    /**
     * Deletes a fee.
     *
     * @param string $type
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($type)
    {
        if (!Auth::user()->isAdmin()) {
            return response()->json(['message' => 'Accès non autorisé'], 403);
        }

        $fee = Fee::where('type', $type)->firstOrFail();

        if ($fee->is_active && Fee::where('type', $fee->type)->where('is_active', true)->count() <= 1) {
            return response()->json([
                'message' => 'Impossible de supprimer le seul frais actif de ce type. Veuillez activer un autre frais de ce type avant de supprimer celui-ci.'
            ], 400);
        }

        $fee->delete();

        return response()->json([
            'message' => 'Frais supprimé avec succès'
        ]);
    }

    /**
     * Activates a fee and deactivates others of the same type.
     *
     * @param string $type
     * @return \Illuminate\Http\JsonResponse
     */
    public function activate($type)
    {
        if (!Auth::user()->isAdmin()) {
            return response()->json(['message' => 'Accès non autorisé'], 403);
        }

        $fee = Fee::where('type', $type)->firstOrFail();

        Fee::where('type', $fee->type)
            ->where('type', '!=', $type)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        $fee->is_active = true;
        $fee->save();

        return response()->json([
            'message' => 'Frais activé avec succès',
            'fee' => $fee
        ]);
    }

    /**
     * Gets active fees for each type.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getActiveFees()
    {
        $orderFee = Fee::where('type', 'order')
            ->where('is_active', true)
            ->first();

        $penaltyFee = Fee::where('type', 'penalty')
            ->where('is_active', true)
            ->first();

        return response()->json([
            'order_fee' => $orderFee,
            'penalty_fee' => $penaltyFee
        ]);
    }
}