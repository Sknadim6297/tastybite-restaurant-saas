@extends('layouts.restaurant-admin')

@section('title', 'Manage Tables')
@section('section', 'Table Management')

@section('content')
    @include('restaurant.tables.partials.heading', ['title' => 'Manage Tables', 'description' => 'Manage floors, dining sections, and restaurant tables.'])
    @if(session('status'))<div class="notice-card" role="status">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="notice-card notice-card-error" role="alert">{{ $errors->first() }}</div>@endif

    <section class="table-kpi-grid" aria-label="Table setup summary">
        @foreach([['Floors', $floorCount], ['Sections', $sectionCount], ['Tables', $tableCount], ['Seating capacity', $totalCapacity]] as [$label, $value])
            <article class="table-kpi-card"><small>{{ $label }}</small><strong>{{ number_format((int) $value) }}</strong></article>
        @endforeach
    </section>

    <nav class="table-workspace-tabs" role="tablist" aria-label="Manage table setup">
        @foreach(['tables' => 'All Tables', 'floors' => 'Floors', 'sections' => 'Sections'] as $value => $label)
            <a role="tab" aria-selected="{{ $tab === $value ? 'true' : 'false' }}" @class(['is-active' => $tab === $value]) href="{{ route('restaurant.tables.manage', ['tab' => $value]) }}">{{ $label }}</a>
        @endforeach
    </nav>

    @if($tab === 'tables')
        @include('restaurant.tables.partials.manage-tables')
    @elseif($tab === 'floors')
        @include('restaurant.tables.partials.manage-floors')
    @else
        @include('restaurant.tables.partials.manage-sections')
    @endif
@endsection