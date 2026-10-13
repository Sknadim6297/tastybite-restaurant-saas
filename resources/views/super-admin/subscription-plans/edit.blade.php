@extends('layouts.super-admin')

@section('title', 'Edit Subscription Plan')
@section('section', 'Subscription Plans')

@section('content')
    <div class="page-heading"><div><span class="eyebrow">BILLING CONFIGURATION</span><h1>Edit {{ $plan->name }}</h1><p>Existing subscriptions keep their stored plan name, price, and duration.</p></div></div>
    @include('super-admin.subscription-plans._form', ['plan' => $plan])
@endsection
