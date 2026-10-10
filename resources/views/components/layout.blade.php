@php($tenant = app(\App\Tenancy\TenantContext::class)->tenant)
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Overview' }} · GrowthDesk</title>
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
    @livewireStyles
</head>
<body>
<a class="skip" href="#main">Skip to content</a>
<div class="app-shell">
    <aside class="sidebar">
        <a class="brand" href="{{ route('dashboard') }}"><span class="brand-icon">G</span> GrowthDesk<span class="brand-dot">.</span></a>
        <p class="nav-caption">WORKSPACE</p>
        <nav aria-label="Main navigation">
            @foreach(['dashboard' => ['◈', 'Overview'], 'contacts.index' => ['◎', 'Contacts'], 'companies.index' => ['▣', 'Companies'], 'pipeline' => ['▥', 'Sales pipeline'], 'tasks.index' => ['☑', 'Follow-ups'], 'integrations' => ['⌘', 'Integrations'], 'activity' => ['◷', 'Activity log']] as $route => [$icon, $label])
                <a href="{{ route($route) }}" class="nav-link {{ request()->routeIs($route) || ($route === 'contacts.index' && request()->routeIs('contacts.*')) ? 'active' : '' }}"><span aria-hidden="true">{{ $icon }}</span>{{ $label }}</a>
            @endforeach
            @can('super-admin')<a class="nav-link" href="{{ route('admin') }}"><span aria-hidden="true">⚙</span> Super Admin</a>@endcan
        </nav>
        <div class="sidebar-footer"><span class="pill">PHASE 1</span><h3>A clearer view of growth.</h3><p>Your contacts, pipeline and next steps, together.</p><a href="{{ route('integrations') }}">Explore integrations →</a></div>
        <div class="profile"><span class="avatar">{{ mb_substr(auth()->user()->name, 0, 1) }}</span><div><strong>{{ auth()->user()->name }}</strong><small>{{ app(\App\Tenancy\TenantContext::class)->role?->label() ?? 'Super Admin' }}</small></div></div>
    </aside>
    <div class="main-shell">
        <header class="topbar">
            <span class="breadcrumb">Workspace <span>/</span> {{ $title ?? 'Overview' }}</span>
            <div class="topbar-actions">
                @if($tenant)
                <form method="post" action="{{ route('tenant.switch') }}" class="business-switch">@csrf<label class="sr-only" for="tenant-select">Business</label><select name="tenant_id" id="tenant-select">@foreach(auth()->user()->tenants as $business)<option value="{{ $business->id }}" @selected($business->id === $tenant->id)>{{ $business->name }}</option>@endforeach</select><button class="btn-small" type="submit">Switch</button></form>
                @endif
                <form action="{{ route('logout') }}" method="post">@csrf<button class="text-button">Sign out</button></form>
            </div>
        </header>
        <main id="main">
            @if($tenant?->is_demo)<div class="demo-banner"><span class="status-dot"></span> Demo workspace <span>·</span> Sample records for testing. No external messages or payments.</div>@endif
            @if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
            @if($errors->any())<div class="error" role="alert"><strong>Please check your input.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            {{ $slot }}
            <footer class="page-footer">GrowthDesk <span>·</span> Business growth, with context.</footer>
        </main>
    </div>
</div>
@livewireScripts
<script src="{{ asset('js/forms.js') }}" defer></script>
</body></html>
