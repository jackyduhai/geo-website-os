<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * 后台认证：单管理员、无注册、无找回（本地/内网运维，密码可在后台修改）
 */
class AuthController extends Controller
{
    public function showLogin(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('admin.dashboard');
        }

        return view('admin.auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $key = 'admin-login:' . $request->ip() . '|' . $credentials['email'];
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()
                ->withInput($request->only('email'))
                ->with('error', '尝试次数过多，请 ' . RateLimiter::availableIn($key) . ' 秒后再试');
        }

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($key, 300);

            return back()
                ->withInput($request->only('email'))
                ->with('error', '邮箱或密码不正确');
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        return redirect()->intended(route('admin.dashboard'))
            ->with('success', '欢迎回来');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }

    public function showPassword(): View
    {
        return view('admin.auth.password');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password'         => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ]);

        $user = $request->user();
        $user->password = Hash::make($data['password']);
        $user->save();

        return back()->with('success', '密码已修改');
    }
}
