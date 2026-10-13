@extends('layouts.super-admin')

@section('title', 'Create Subscription Plan')
@section('section', 'Subscription Plans')

@section('content')
    <div class="page-heading"><div><span class="eyebrow">BILLING CONFIGURATION</span><h1>Create subscription plan</h1><p>Plan changes do not alter the saved terms of subscriptions already assigned.</p></div></div>
    @include('super-admin.subscription-plans._form')
@endsection
