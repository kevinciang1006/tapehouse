@extends('layouts.app')

@section('title', 'Tapehouse — Sign in')

@section('body')
<div class="auth-shell">
    <div class="auth-card">
        <div class="auth-card__wordmark">TAPEHOUSE</div>
        <div class="label auth-card__subtitle">Operator sign in</div>

        <form method="POST" action="{{ route('login') }}" class="auth-card__form">
            @csrf

            <div class="form-group">
                <label for="email">Email</label>
                <input
                    id="email"
                    name="email"
                    type="email"
                    value="{{ old('email') }}"
                    class="form-input"
                    required
                    autofocus
                >
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input
                    id="password"
                    name="password"
                    type="password"
                    class="form-input"
                    required
                >
            </div>

            @error('email')
                <p role="alert" class="form-error">{{ $message }}</p>
            @enderror

            <button type="submit" class="btn btn--signal auth-card__submit">Sign in</button>
        </form>

        <div class="auth-card__footnote">Demo instance. Sign in with operator@tapehouse.dev / tapehouse. Running on a Twelve Data trial key, so the feed falls back to polling when streaming credits run out.</div>
    </div>
</div>
@endsection
