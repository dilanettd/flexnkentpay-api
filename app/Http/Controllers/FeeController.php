<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Fee;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class FeeController extends Controller
{
    /**
     * Affiche tous les frais.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        // Vérifier que l'utilisateur est admin
        if (!Auth::user()->isAdmin()) {
            return response()->json(['message' => 'Accès non autorisé'], 403);
        }

        $fees = Fee::all();

        return response()->json($fees);
    }

    /**
     * Crée un nouveau frais.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        // Vérifier que l'utilisateur est admin
        if (!Auth::user()->isAdmin()) {
            return response()->json(['message' => 'Accès non autorisé'], 403);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'type' => ['required', 'string', Rule::in(['order', 'penalty'])],
            'percentage' => 'required|numeric|min:0|max:100',
            'is_active' => 'sometimes|boolean',
        ]);

        // Si un frais du même type est actif, le désactiver
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
     * Affiche un frais spécifique.
     *
     * @param string $idname
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($name)
    {

        if (!Auth::user()) {
            return response()->json(['message' => 'Accès non autorisé'], 403);
        }

        $fee = Fee::where('type', $name)->first();

        return response()->json($fee);
    }

    /**
     * Met à jour un frais existant.
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        // Vérifier que l'utilisateur est admin
        if (!Auth::user()->isAdmin()) {
            return response()->json(['message' => 'Accès non autorisé'], 403);
        }

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'type' => ['sometimes', 'string', Rule::in(['order', 'penalty'])],
            'percentage' => 'sometimes|numeric|min:0|max:100',
            'is_active' => 'sometimes|boolean',
        ]);

        $fee = Fee::findOrFail($id);

        // Si nous changeons le type ou activons ce frais
        if (
            ($request->has('type') && $request->type !== $fee->type) ||
            ($request->has('is_active') && $request->is_active && !$fee->is_active)
        ) {

            $type = $request->type ?? $fee->type;

            // Si ce frais devient actif, désactiver les autres du même type
            if ($request->is_active ?? $fee->is_active) {
                Fee::where('type', $type)
                    ->where('id', '!=', $id)
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
     * Supprime un frais.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        // Vérifier que l'utilisateur est admin
        if (!Auth::user()->isAdmin()) {
            return response()->json(['message' => 'Accès non autorisé'], 403);
        }

        $fee = Fee::findOrFail($id);

        // Empêcher la suppression si c'est le seul frais actif de ce type
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
     * Active un frais et désactive les autres du même type.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function activate($id)
    {
        // Vérifier que l'utilisateur est admin
        if (!Auth::user()->isAdmin()) {
            return response()->json(['message' => 'Accès non autorisé'], 403);
        }

        $fee = Fee::findOrFail($id);

        // Désactiver tous les autres frais du même type
        Fee::where('type', $fee->type)
            ->where('id', '!=', $id)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        // Activer ce frais
        $fee->is_active = true;
        $fee->save();

        return response()->json([
            'message' => 'Frais activé avec succès',
            'fee' => $fee
        ]);
    }

    /**
     * Récupère les frais actifs pour chaque type.
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