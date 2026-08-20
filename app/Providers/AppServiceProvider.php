<?php

namespace App\Providers;

use App\Auth\SendLoginLink;
use App\Events\CertificateExpired;
use App\Events\CertificateExpiring;
use App\Events\DocumentTransitioned;
use App\Events\IssuerActivated;
use App\Events\IssuerStatusChanged;
use App\Lhdn\Fake\FakeLhdnClient;
use App\Lhdn\LhdnDriverGuard;
use App\Listeners\DispatchCertificateWebhooks;
use App\Listeners\DispatchDocumentWebhooks;
use App\Listeners\DispatchIssuerWebhooks;
use App\Listeners\HoldDocumentsOnSuspension;
use App\Listeners\PrepareDocumentOnQueued;
use App\Listeners\ReleaseChildrenOnConsolidationFailure;
use App\Listeners\ReleaseHeldDocumentsOnActivation;
use App\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app->singleton(FakeLhdnClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        LhdnDriverGuard::check($this->app);

        // spec 3.3: throttle per credential, not per IP, so one merchant's
        // traffic cannot exhaust another's budget behind a shared NAT. The
        // bearer token is hashed so it never reaches the cache store or logs.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute((int) config('einvoice.rate_limit_per_minute', 60))
            ->by($request->bearerToken() !== null
                ? 'cred:'.hash('sha256', (string) $request->bearerToken())
                : 'ip:'.$request->ip()));

        // Dashboard (Plan 5, spec §4.2): magic-link requests are throttled both
        // per (normalized) email and per IP so neither a targeted email nor a
        // shared NAT can exhaust the other axis's budget. A 429 on either must
        // never reveal whether the email exists — see MagicLinkTest.
        RateLimiter::for('login-link-email', fn (Request $request) => Limit::perMinute(5)
            ->by('email:'.SendLoginLink::normalize((string) $request->input('email', ''))));

        RateLimiter::for('login-link-ip', fn (Request $request) => Limit::perMinute(5)
            ->by('ip:'.$request->ip()));

        // DispatchDocumentWebhooks must run before PrepareDocumentOnQueued: on the
        // sync queue, PrepareDocumentOnQueued recursively runs the rest of the
        // submission pipeline inline (prepare -> submit -> poll), so if it ran
        // first the webhook for THIS transition would be recorded only after every
        // later transition's webhook, scrambling delivery order for the caller.
        Event::listen(DocumentTransitioned::class, DispatchDocumentWebhooks::class);
        Event::listen(DocumentTransitioned::class, PrepareDocumentOnQueued::class);
        Event::listen(DocumentTransitioned::class, ReleaseChildrenOnConsolidationFailure::class);
        Event::listen(IssuerActivated::class, ReleaseHeldDocumentsOnActivation::class);
        Event::listen(IssuerStatusChanged::class, DispatchIssuerWebhooks::class);
        // After DispatchIssuerWebhooks so the issuer.status_changed delivery is
        // recorded before the document.held ones its suspension causes.
        Event::listen(IssuerStatusChanged::class, HoldDocumentsOnSuspension::class);
        Event::listen(CertificateExpiring::class, [DispatchCertificateWebhooks::class, 'handleExpiring']);
        Event::listen(CertificateExpired::class, [DispatchCertificateWebhooks::class, 'handleExpired']);
    }
}
