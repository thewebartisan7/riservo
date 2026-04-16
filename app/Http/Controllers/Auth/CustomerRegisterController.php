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
        $email = $request->validated('email');

        $customer = Customer::firstOrCreate(
            ['email' => $email],
            ['name' => $request->validated('name')],
        );

        $user = User::create([
            'name' => $request->validated('name'),
            'email' => $email,
            'password' => $request->validated('password'),
        ]);

        $user->markEmailAsVerified();

        $customer->update(['user_id' => $user->id]);

        Auth::login($user);

        return redirect()->route('customer.bookings');
    }
}
