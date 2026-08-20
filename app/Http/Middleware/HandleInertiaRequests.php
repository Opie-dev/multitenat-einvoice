<?php

namespace App\Http\Middleware;

use App\Enums\Environment;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * `tenant` and `environment` are wired up in Plan 5 Tasks 2/3 (users,
     * magic-link auth, EnsureDashboardTenantContext). Until then there is no
     * web auth guard, so `$request->user()` is always null; this method is
     * still written against the real request/session so those later tasks
     * only need to populate data, not change this shape.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $this->sharedUser($request),
            ],
            'tenant' => null,
            'environment' => $this->sharedEnvironment($request),
            'flash' => [
                'success' => $this->sharedString($request, 'success'),
                'secret' => $this->sharedString($request, 'secret'),
            ],
        ];
    }

    /**
     * @return array{id: string, name: string, email: string, role: string|null, issuer_id: string|null}|null
     */
    private function sharedUser(Request $request): ?array
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return null;
        }

        return [
            'id' => (string) $user->getAttribute('id'),
            'name' => (string) $user->getAttribute('name'),
            'email' => (string) $user->getAttribute('email'),
            // Role and issuer scoping land on the user model in Plan 5 Tasks 2/3.
            'role' => null,
            'issuer_id' => null,
        ];
    }

    private function sharedEnvironment(Request $request): ?string
    {
        $value = $request->session()->get('environment');

        if (! is_string($value) || Environment::tryFrom($value) === null) {
            return null;
        }

        return $value;
    }

    private function sharedString(Request $request, string $key): ?string
    {
        $value = $request->session()->get($key);

        return is_string($value) ? $value : null;
    }
}
