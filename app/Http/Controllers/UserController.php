<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;

class UserController extends Controller
{
    public function updateProfile(Request $request)
    {
        $user = Auth::user();

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|string|email|max:255|unique:users,email,' . $user->id,
            'phone' => 'sometimes|string|max:15|nullable',
        ]);

        $updates = $request->only(['name', 'email', 'phone']);
        $user->update($updates);

        return response()->json($user);
    }

    public function updateStatus(Request $request, string $id)
    {
        if (!Auth::user()->isAdmin()) {
            return response()->json(['message' => 'Accès non autorisé'], 403);
        }

        $request->validate([
            'is_active' => 'required|boolean',
        ]);

        $user = User::findOrFail($id);

        if ($user->id === Auth::id()) {
            return response()->json(['message' => 'Vous ne pouvez pas désactiver votre propre compte'], 400);
        }

        $user->is_active = $request->is_active;
        $user->save();

        $statusMessage = $request->is_active ? 'activated' : 'deactivated';

        return response()->json([
            'message' => "Le compte de l'utilisateur a été $statusMessage avec succès.",
            'user' => $user
        ]);
    }


    public function updateProfilePicture(Request $request)
    {
        $user = Auth::user();

        $request->validate([
            'profilePic' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        if ($request->hasFile('profilePic')) {
            // Delete old profile picture if exists
            if ($user->profile_url) {
                // Get image path relative to the public directory
                $path = str_replace(url('/'), '', $user->profile_url);
                $path = ltrim($path, '/');

                // Delete from public storage if file exists
                if (file_exists(public_path($path))) {
                    unlink(public_path($path));
                }
            }

            // Destination path within public directory
            $destinationPath = 'uploads/users/profiles';

            // Create directory if it doesn't exist
            if (!file_exists(public_path($destinationPath))) {
                mkdir(public_path($destinationPath), 0755, true);
            }

            // Generate a unique filename
            $fileName = uniqid() . '_' . time() . '.' . $request->file('profilePic')->getClientOriginalExtension();

            // Move the uploaded file to the public directory
            $request->file('profilePic')->move(public_path($destinationPath), $fileName);

            // Generate the URL for direct access
            $user->profile_url = url($destinationPath . '/' . $fileName);
            $user->save();

            return response()->json($user);
        }

        return response()->json(['message' => 'Profile picture update failed'], 400);
    }

    public function changeRole(Request $request)
    {
        $user = Auth::user();

        $request->validate([
            'role' => 'required|in:customer,seller,admin'
        ]);

        $user->role = $request->role;
        $user->save();

        return response()->json($user);
    }

    /**
     * Récupère tous les utilisateurs avec pagination et recherche.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAllUsers(Request $request)
    {
        if (!Auth::user()->isAdmin()) {
            return response()->json(['message' => 'Accès non autorisé'], 403);
        }

        $query = User::query();

        if ($request->has('keyword') && !empty($request->keyword)) {
            $keyword = $request->keyword;
            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")
                    ->orWhere('email', 'like', "%{$keyword}%")
                    ->orWhere('phone', 'like', "%{$keyword}%")
                    ->orWhere('role', 'like', "%{$keyword}%");
            });
        }

        if ($request->has('role') && !empty($request->role)) {
            $query->where('role', $request->role);
        }

        if ($request->has('is_active') && $request->is_active !== null) {
            $isActive = filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN);
            $query->where('is_active', $isActive);
        }

        $sortField = $request->sort_field ?? 'created_at';
        $sortDirection = $request->sort_direction ?? 'desc';
        $query->orderBy($sortField, $sortDirection);

        $perPage = $request->per_page ?? 15;
        $users = $query->paginate($perPage);

        return response()->json($users);
    }
}