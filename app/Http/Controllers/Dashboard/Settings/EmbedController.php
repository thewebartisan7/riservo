<?php

namespace App\Http\Controllers\Dashboard\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmbedController extends Controller
{
    public function edit(Request $request): Response
    {
        $business = $request->user()->currentBusiness();

        $baseUrl = url($business->slug);
        $embedUrl = $baseUrl.'?embed=1';
        $appUrl = url('/');

        $services = $business->services()
            ->where('is_active', true)
            ->get(['id', 'name', 'slug'])
            ->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'slug' => $s->slug,
            ]);

        return Inertia::render('dashboard/settings/embed', [
            'slug' => $business->slug,
            'baseUrl' => $baseUrl,
            'embedUrl' => $embedUrl,
            'appUrl' => $appUrl,
            'services' => $services,
        ]);
    }
}
