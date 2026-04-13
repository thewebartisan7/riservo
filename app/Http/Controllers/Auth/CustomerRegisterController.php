<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\CustomerRegisterRequest;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class CustomerRegisterController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('auth/customer-register');
    }

    public function store(CustomerRegisterRequest $request): RedirectResponse
    {
        $customer = Customer::where('email', $request->validated('email'))->first();

        if ($customer->user_id) {
            return redirect()->route('login')
                ->with('status', __('An account already exists for this email. Please log in.'));
        }

        $user = User::create([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'password' => $request->validated('password'),
        ]);

        $user->markEmailAsVerified();

        $customer->update(['user_id' => $user->id]);

        Auth::login($user);

        return redirect()->route('customer.bookings');
    }
}
