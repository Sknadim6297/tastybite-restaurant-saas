@extends('layouts.restaurant')

@section('title', 'Tasty Bite POS')
@section('content')
@php
    $restaurantName = $restaurant->name ?? 'TastyBite';
    $adminAccess = request()->attributes->get('restaurant_admin_access_admin');
    $currentUser = $adminAccess ?? ($currentUser ?? Auth::guard('web')->user());
    $staffName = $currentUser?->name ?? 'Staff';
    $staffRole = $adminAccess ? 'Super Admin' : ucfirst((string) ($currentUser?->role ?? 'owner'));
@endphp

    <main class="main-content">
      <div class="pos-layout" id="posLayout">
        <section class="pos-left" aria-label="Menu and products">
    <header class="top-header">
      <a href="{{ route('pos.index') }}" class="brand">
        @if($restaurant->logo_url)
          <img class="brand-icon brand-logo-image" src="{{ $restaurant->logo_url }}" alt="{{ $restaurantName }} logo">
        @else
          <div class="brand-icon"><i class="bi bi-cup-hot-fill"></i></div>
        @endif
        <div class="brand-text">
          <h2>{{ $restaurantName }}</h2>
          <small>Restaurant Admin <span aria-hidden="true">/</span> POS Billing</small>
        </div>
      </a>

      <div class="header-search">
        <input
          type="search"
          id="menuSearch"
          placeholder="Search for items..."
          aria-label="Search menu"
          autocomplete="off"
        />
        <button class="search-clear" type="button" data-search-clear aria-label="Clear menu search" hidden>
          <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
        <button class="search-submit" type="button" data-search-focus aria-label="Search menu">
          <i class="bi bi-search" aria-hidden="true"></i>
        </button>
      </div>

      <div class="header-right">
        <div class="header-date" aria-label="Current date and time in Kolkata">
          <i class="bi bi-calendar3"></i>
          <span id="currentDate">-- --- ----</span>
          <span class="header-date-separator" aria-hidden="true">|</span>
          <span id="currentTime">--:--:-- --</span>
        </div>

        <a class="pos-notifications" href="{{ route('restaurant.announcements.index') }}" aria-label="Announcements">
          <i class="bi bi-bell" aria-hidden="true"></i>
        </a>

        @if ($adminAccess)
          <div class="admin-profile" aria-label="Super Admin account">
            <span class="admin-avatar"><i class="bi bi-person-fill"></i></span>
            <span class="admin-meta">
              <span class="admin-name">{{ $staffName }}</span>
              <small>{{ $staffRole }}</small>
            </span>
          </div>
        @else
          <div class="dropdown">
            <button class="admin-profile" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Account menu">
              <span class="admin-avatar"><i class="bi bi-person-fill"></i></span>
              <span class="admin-meta">
                <span class="admin-name">{{ $staffName }}</span>
                <small>{{ $staffRole }}</small>
              </span>
              <i class="bi bi-chevron-down admin-caret" aria-hidden="true"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end admin-menu">
              <li><a class="dropdown-item" href="{{ route('restaurant.profile') }}"><i class="bi bi-person-gear"></i> Profile &amp; Branding</a></li>
              <li><a class="dropdown-item" href="{{ route('restaurant.dashboard') }}"><i class="bi bi-speedometer2"></i> Dashboard</a></li>
              <li><hr class="dropdown-divider"></li>
              <li>
                <form method="POST" action="{{ route('restaurant.logout') }}">
                  @csrf
                  <button type="submit" class="dropdown-item"><i class="bi bi-box-arrow-right"></i> Log out</button>
                </form>
              </li>
            </ul>
          </div>
        @endif
      </div>
    </header>

          <section class="menu-panel">
            <section class="pos-order-controls" aria-label="Current order details">
              <div class="pos-order-control-grid">
                <div class="pos-control-group">
                  <span class="pos-control-label">Order Type</span>