<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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
}