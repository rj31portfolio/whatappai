<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Sign in · GrowthDesk</title><link rel="stylesheet" href="{{ asset('css/dashboard.css') }}"></head>
<body class="login-page"><main class="login-card"><a class="brand" href="{{ route('login') }}"><span class="brand-icon">G</span> GrowthDesk.</a><p class="eyebrow">YOUR GROWTH WORKSPACE</p><h1>Welcome back.</h1><p class="muted">A little clarity. A lot of possibility.</p>
@if($errors->any())<div class="error" role="alert">{{ $errors->first() }}</div>@endif
<form method="post" action="{{ route('login') }}">@csrf
<label for="email">Email address</label><input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
<label for="password">Password</label><input id="password" type="password" name="password" required autocomplete="current-password">
<button class="btn full" type="submit">Sign in →</button></form>
<p class="small muted"><a href="{{ route('password.request') }}">Forgot your password?</a></p></main><script src="{{ asset('js/forms.js') }}" defer></script></body></html>
