<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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
            if ($user->profile_url) {
                $urlParts = explode('/', $user->profile_url);
                $filename = end($urlParts);
                Storage::disk('s3')->delete('images/profiles/' . $filename);
            }

            $image = $request->file('profilePic');
            $extension = $image->getClientOriginalExtension();
            $imageName = 'images/profiles/' . uniqid() . "." . $extension;
            Storage::disk('s3')->put($imageName, file_get_contents($image));

            $user->profile_url = Storage::disk('s3')->url($imageName);
            Storage::disk('s3')->setVisibility($user->profile_url, 'public');
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

