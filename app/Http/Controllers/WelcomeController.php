<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WelcomeController extends Controller
{
    public function show(Request $request): Response
    {
        $business = $request->user()->currentBusiness();

        return Inertia::render('dashboard/welcome', [
            'publicUrl' => url($business->slug),
            'businessName' => $business->name,
        ]);
    }
}
