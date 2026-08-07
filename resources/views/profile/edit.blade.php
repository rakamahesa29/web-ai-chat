@extends('layouts.app')

@section('content')
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-bold text-hermes-text">Profile Settings</h1>
            <p class="text-hermes-muted text-sm mt-1">Manage your account information and preferences.</p>
        </div>

        <div class="hermes-card p-6">
            <div class="max-w-xl">
                @include('profile.partials.update-profile-information-form')
            </div>
        </div>

        <div class="hermes-card p-6">
            <div class="max-w-xl">
                @include('profile.partials.update-password-form')
            </div>
        </div>

        <div class="hermes-card p-6 border-hermes-danger/30">
            <div class="max-w-xl">
                @include('profile.partials.delete-user-form')
            </div>
        </div>
    </div>
@endsection
