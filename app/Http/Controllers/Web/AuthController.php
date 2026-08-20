<?php

namespace App\Http\Controllers\Web;

use App\Auth\ConsumeLoginToken;
use App\Auth\SendLoginLink;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    /**
     * Always renders the same page regardless of whether the email matched a
     * user — enumeration safety (spec §4.2, §9) lives in SendLoginLink and here:
     * this method has no branch on the action's outcome because it has none to
     * branch on (handle() returns void).
     */
    public function sendLink(Request $request, SendLoginLink $sendLoginLink): Response
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:191'],
        ]);

        $sendLoginLink->handle($validated['email']);

        return Inertia::render('Auth/LinkSent');
    }

    public function consume(string $token, Request $request, ConsumeLoginToken $consumeLoginToken): Response|RedirectResponse
    {
        $user = $consumeLoginToken->handle($token);

        if ($user === null) {
            return Inertia::render('Auth/LinkInvalid');
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended('/dashboard');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
