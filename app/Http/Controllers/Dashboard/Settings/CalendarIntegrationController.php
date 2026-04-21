<?php

namespace App\Http\Controllers\Dashboard\Settings;

use App\Enums\PendingActionStatus;
use App\Http\Controllers\Controller;
use App\Jobs\Calendar\PullCalendarEventsJob;
use App\Jobs\Calendar\StartCalendarSyncJob;
use App\Models\CalendarIntegration;
use App\Models\PendingAction;
use App\Services\Calendar\CalendarProviderFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

class CalendarIntegrationController extends Controller
{
    /**
     * OAuth scopes requested from Google on connect.
     *
     * @var array<int, string>
     */
    private const GOOGLE_SCOPES = [
        'openid',
        'email',
        'https://www.googleapis.com/auth/calendar.events',
        'https://www.googleapis.com/auth/calendar',
    ];

    public function __construct(
        private readonly CalendarProviderFactory $providerFactory,
    ) {}

    public function index(Request $request): Response
    {
        $integration = $request->user()->calendarIntegration;
        $business = tenant()->business();

        $destinationCalendar = null;
        $conflictCalendarIds = [];
        $pinnedBusinessName = null;
        $pinnedBusinessMismatch = false;

        if ($integration && $integration->isConfigured()) {
            $destinationCalendar = $integration->destination_calendar_id;
            $conflictCalendarIds = $integration->conflict_calendar_ids ?? [];
            $integration->loadMissing('business');
            $pinnedBusinessName = $integration->business?->name;
            $pinnedBusinessMismatch = $business !== null
                && $integration->business_id !== null
                && $integration->business_id !== $business->id;
        }

        return Inertia::render('dashboard/settings/calendar-integration', [
            'connected' => $integration !== null,
            'configured' => $integration?->isConfigured() ?? false,
            'googleAccountEmail' => $integration?->google_account_email,
            'destinationCalendarId' => $destinationCalendar,
            'conflictCalendarIds' => $conflictCalendarIds,
            'lastSyncedAt' => $integration?->last_synced_at?->toIso8601String(),
            'pendingActionsCount' => $this->pendingActionsCountForViewer($request, $integration),
            'pinnedBusinessName' => $pinnedBusinessName,
            'pinnedBusinessMismatch' => $pinnedBusinessMismatch,
            'error' => $integration?->sync_error,
        ]);
    }

    public function connect(Request $request): HttpResponse
    {
        $driver = Socialite::driver('google');

        if (! $driver instanceof AbstractProvider) {
            throw new \LogicException('Google Socialite driver must be an OAuth2 AbstractProvider.');
        }

        $redirect = $driver
            ->scopes(self::GOOGLE_SCOPES)
            ->with(['access_type' => 'offline', 'prompt' => 'consent'])
            ->redirect();

        if ($request->header('X-Inertia')) {
            return Inertia::location($redirect->getTargetUrl());
        }

        return $redirect;
    }

    public function callback(Request $request): RedirectResponse
    {
        if ($request->filled('error')) {
            return redirect()
                ->route('settings.calendar-integration')
                ->with('error', $this->humaniseOAuthError($request->string('error')->toString()));
        }

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            report($e);

            return redirect()
                ->route('settings.calendar-integration')
                ->with('error', __('Could not complete the Google Calendar connection. Please try again.'));
        }

        if (! $googleUser instanceof SocialiteUser) {
            throw new \LogicException('Google Socialite user must be an OAuth2 user.');
        }

        $existing = $request->user()->calendarIntegration;

        $refreshToken = $googleUser->refreshToken ?: $existing?->refresh_token;

        $tokenExpiresAt = $googleUser->expiresIn !== null
            ? now()->addSeconds((int) $googleUser->expiresIn)
            : null;

        CalendarIntegration::updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'provider' => 'google',
            ],
            [
                'access_token' => $googleUser->token,
                'refresh_token' => $refreshToken,
                'token_expires_at' => $tokenExpiresAt,
                'google_account_email' => $googleUser->getEmail(),
            ],
        );

        return redirect()->route('settings.calendar-integration.configure');
    }

    public function configure(Request $request): Response|RedirectResponse
    {
        $integration = $request->user()->calendarIntegration;

        if ($integration === null) {
            return redirect()->route('settings.calendar-integration');
        }

        $business = tenant()->business();

        try {
            $provider = $this->providerFactory->for($integration);
            $calendars = collect($provider->listCalendars($integration))
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'summary' => $c->summary,
                    'primary' => $c->primary,
                    'accessRole' => $c->accessRole,
                ])
                ->values()
                ->all();
        } catch (Throwable $e) {
            report($e);
            $calendars = [];
        }

        // Primary calendar pre-selected for conflicts (locked decision #4). If we
        // already configured previously, preserve the user's choices.
        $primaryId = collect($calendars)->firstWhere('primary', true)['id'] ?? null;

        $destination = $integration->destination_calendar_id ?? $primaryId;
        $conflicts = $integration->conflict_calendar_ids
            ?? array_filter([$primaryId]);

        return Inertia::render('dashboard/settings/calendar-integration-configure', [
            'calendars' => $calendars,
            'destinationCalendarId' => $destination,
            'conflictCalendarIds' => array_values($conflicts),
            'businessName' => $business?->name,
        ]);
    }

    public function saveConfiguration(Request $request): RedirectResponse
    {
        $integration = $request->user()->calendarIntegration;

        if ($integration === null) {
            return redirect()->route('settings.calendar-integration');
        }

        $business = tenant()->business();

        $validated = $request->validate([
            'destination_calendar_id' => ['nullable', 'string'],
            'conflict_calendar_ids' => ['array'],
            'conflict_calendar_ids.*' => ['string'],
            'create_new_calendar_name' => ['nullable', 'string', 'max:120'],
        ]);

        $destinationId = $validated['destination_calendar_id'] ?? null;

        if (! empty($validated['create_new_calendar_name'])) {
            try {
                $provider = $this->providerFactory->for($integration);
                $destinationId = $provider->createCalendar($integration, $validated['create_new_calendar_name']);
            } catch (Throwable $e) {
                report($e);

                return back()->with('error', __('Could not create the new calendar. Please try again.'));
            }
        }

        if (! $destinationId) {
            return back()->with('error', __('Please choose a destination calendar.'));
        }

        // Round-2 review: if the integration is being repinned to a different
        // business than it was previously attached to, every piece of integration-
        // scoped state from the old business must be torn down first. Otherwise
        // old `calendar_watches` + their per-watch `sync_token`s get silently
        // reused under the new business, and old pending actions orphan out of
        // view of every tenant. The OAuth tokens themselves survive — the user
        // does not have to re-consent on Google.
        $isRepinningToNewBusiness = $integration->business_id !== null
            && $business?->id !== null
            && $integration->business_id !== $business->id;

        if ($isRepinningToNewBusiness) {
            $this->resetIntegrationScopedState($integration);
        }

        $integration->forceFill([
            'business_id' => $business?->id,
            'destination_calendar_id' => $destinationId,
            'conflict_calendar_ids' => array_values(array_unique($validated['conflict_calendar_ids'] ?? [])),
        ])->save();

        StartCalendarSyncJob::dispatch($integration->id);

        return redirect()
            ->route('settings.calendar-integration')
            ->with('success', __('Google Calendar configured. Sync will start shortly.'));
    }

    /**
     * Stop every watch in Google (best-effort), delete watch rows + pending
     * actions, and clear timing/error columns on the integration. Called when
     * the integration is about to be repinned to a different business.
     */
    private function resetIntegrationScopedState(CalendarIntegration $integration): void
    {
        try {
            $provider = $this->providerFactory->for($integration);
            foreach ($integration->watches as $watch) {
                try {
                    $provider->stopWatch($integration, $watch->channel_id, $watch->resource_id);
                } catch (Throwable $e) {
                    // Dead / expired channel must not block local teardown.
                    report($e);
                }
            }
        } catch (Throwable $e) {
            report($e);
        }

        DB::transaction(function () use ($integration) {
            $integration->watches()->delete();
            $integration->pendingActions()->delete();
        });

        $integration->forceFill([
            'last_synced_at' => null,
            'last_pushed_at' => null,
            'sync_error' => null,
            'sync_error_at' => null,
            'push_error' => null,
            'push_error_at' => null,
        ])->save();
    }

    public function syncNow(Request $request): RedirectResponse
    {
        $integration = $request->user()->calendarIntegration;

        if ($integration === null || ! $integration->isConfigured()) {
            return back()->with('error', __('Calendar integration is not configured yet.'));
        }

        foreach ($integration->watches as $watch) {
            PullCalendarEventsJob::dispatch($integration->id, $watch->calendar_id);
        }

        return back()->with('success', __('Sync triggered.'));
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $integration = $request->user()->calendarIntegration;

        if ($integration === null) {
            return redirect()
                ->route('settings.calendar-integration')
                ->with('success', __('Google Calendar disconnected.'));
        }

        // Best-effort stopWatch per watched calendar. A dead / already-stopped
        // channel must not block local row deletion.
        try {
            $provider = $this->providerFactory->for($integration);
            foreach ($integration->watches as $watch) {
                try {
                    $provider->stopWatch($integration, $watch->channel_id, $watch->resource_id);
                } catch (Throwable $e) {
                    report($e);
                }
            }
        } catch (Throwable $e) {
            report($e);
        }

        DB::transaction(function () use ($integration) {
            $integration->watches()->delete();
            $integration->pendingActions()->delete();
            $integration->delete();
        });

        return redirect()
            ->route('settings.calendar-integration')
            ->with('success', __('Google Calendar disconnected.'));
    }

    private function humaniseOAuthError(string $code): string
    {
        return match ($code) {
            'access_denied' => __('Google Calendar connection was cancelled.'),
            default => __('Google declined the connection request. Please try again.'),
        };
    }

    private function pendingActionsCountForViewer(Request $request, ?CalendarIntegration $integration): int
    {
        $business = tenant()->business();

        if ($business === null) {
            return 0;
        }

        $isAdmin = tenant()->role()->value === 'admin';

        $query = PendingAction::where('business_id', $business->id)
            ->where('status', PendingActionStatus::Pending->value);

        if (! $isAdmin) {
            $query->whereHas('integration', fn ($q) => $q->where('user_id', $request->user()->id));
        }

        return $query->count();
    }
}
