<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Mail;
use App\Mail\AccountConfirmation;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Mail\ResetPassword;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Admin;
use App\Models\Preference;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'language' => 'required|string|in:en,fr',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        $verificationToken = Str::random(64);
        $user->email_verification_token = Hash::make($verificationToken);
        $user->email_verification_token_expires_at = Carbon::now()->addMinutes(120);
        $user->save();

        // Create default user preferences
        Preference::create([
            'user_id' => $user->id,
            'language' => $request->language ?? 'fr',
        ]);

        $frontendUrl = getenv('FRONT_END_URL') . "/account/verify?token=" . urlencode($verificationToken);

        Mail::to($user->email)->send(new AccountConfirmation($user->name, $frontendUrl));

        return response()->json($user, 200);
    }



    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        $credentials = $request->only('email', 'password');

        if (!Auth::attempt($credentials)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $user = Auth::user();

        $tokenResult = $user->createToken('LaravelPassportAuth');
        $accessToken = $tokenResult->accessToken;
        $refreshToken = $tokenResult->token->id;
        $tokenExpiration = $tokenResult->token->expires_at->diffInSeconds(now());

        return response()->json([
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'role' => $user->role,
            'expires_in' => $tokenExpiration
        ], 200);


    }

    public function logout(Request $request)
    {
        $user = Auth::user()->token();
        $user->revoke();

        return response()->json(['message' => 'Successfully logged out']);
    }

    public function getAuthenticatedUser(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $user->load('seller.shop');

        return response()->json($user);
    }

    public function sendResetPasswordEmail(Request $request)
    {
        $request->validate(['email' => 'required|string|email|max:255']);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $token = Str::random(60);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            ['email' => $user->email, 'token' => Hash::make($token), 'created_at' => now()]
        );

        $resetUrl = getenv('FRONT_END_URL') . '/change-password?token=' . $token . '&email=' . urlencode($user->email);

        Mail::to($user->email)->send(new ResetPassword($user->name, $resetUrl));

        return response()->json(['message' => 'Reset password email sent.']);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|string|email|max:255',
            'password' => 'required|string|min:8',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'token'),
            function ($user, $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->save();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json(['message' => 'Password successfully reset. You can now log in.']);
        }

        return response()->json(['message' => 'Password reset failed'], 400);
    }


    public function verifyAccountEmail(Request $request)
    {

        $user = User::where('email_verification_token_expires_at', '>', Carbon::now())
            ->whereNotNull('email_verification_token')
            ->first();

        if (!$user || !Hash::check($request->token, $user->email_verification_token)) {
            return response()->json(['message' => 'Invalid or expired token.'], 400);
        }

        $user->email_verified_at = now();
        $user->email_verification_token = null;
        $user->email_verification_token_expires_at = null;
        $user->save();

        return response()->json(['message' => 'Email verified successfully.'], 200);
    }

    public function loginAdmin(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        $credentials = $request->only('email', 'password');

        if (!Auth::attempt($credentials)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $user = Auth::user();

        if (!$user->isAdmin()) {
            return response()->json(['message' => 'Unauthorized access.'], 403);
        }

        $tokenResult = $user->createToken('LaravelPassportAuth');
        $accessToken = $tokenResult->accessToken;
        $refreshToken = $tokenResult->token->id;
        $tokenExpiration = $tokenResult->token->expires_at->diffInSeconds(now());

        return response()->json([
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'role' => $user->role,
            'expires_in' => $tokenExpiration
        ], 200);
    }

    public function createFirstAdmin(Request $request)
    {
        $adminExists = Admin::exists();
        if ($adminExists) {
            return response()->json(['message' => 'The first admin has already been created.'], 403);
        }

        $request->validate([
            'user_id' => 'required|uuid|exists:users,id',
            'role' => 'required|string',
        ]);

        Admin::create([
            'user_id' => $request->user_id,
            'role' => $request->role,
            'permissions' => $request->permissions ?? [],
        ]);

        return response()->json(['message' => 'First admin successfully created.'], 201);
    }

    public function createAdmin(Request $request)
    {
        $request->validate([
            'user_id' => 'required|uuid|exists:users,id',
            'role' => 'required|string',
        ]);

        $adminExists = Admin::where('user_id', $request->user_id)->exists();
        if ($adminExists) {
            return response()->json(['message' => 'User is already an admin.'], 400);
        }

        Admin::create([
            'user_id' => $request->user_id,
            'role' => $request->role,
            'permissions' => $request->permissions ?? [],
        ]);

        return response()->json(['message' => 'Admin successfully created.'], 201);
    }


    public function addRoleToAdmin(Request $request)
    {
        $request->validate([
            'user_id' => 'required|uuid|exists:admins,user_id',
            'role' => 'required|string',
        ]);

        $admin = Admin::where('user_id', $request->user_id)->first();

        if (!$admin) {
            return response()->json(['message' => 'Admin not found.'], 404);
        }

        $admin->role = $request->role;
        $admin->save();

        return response()->json(['message' => 'Role successfully updated.', 'admin' => $admin], 200);
    }

    public function addPermissionsToAdmin(Request $request)
    {
        $request->validate([
            'user_id' => 'required|uuid|exists:admins,user_id',
            'permissions' => 'required|array',
            'permissions.*' => 'string',
        ]);

        $admin = Admin::where('user_id', $request->user_id)->first();

        if (!$admin) {
            return response()->json(['message' => 'Admin not found.'], 404);
        }

        $existingPermissions = $admin->permissions ?? [];
        $newPermissions = array_unique(array_merge($existingPermissions, $request->permissions));

        $admin->permissions = $newPermissions;
        $admin->save();

        return response()->json(['message' => 'Permissions successfully added.', 'admin' => $admin], 200);
    }


    public function removeAdminPrivileges(Request $request)
    {
        $request->validate([
            'user_id' => 'required|uuid|exists:admins,user_id',
        ]);

        $admin = Admin::where('user_id', $request->user_id)->first();

        if (!$admin) {
            return response()->json(['message' => 'Admin not found.'], 404);
        }

        $admin->delete();

        return response()->json(['message' => 'Admin privileges successfully removed.'], 200);
    }


}
