@extends('layouts.super-admin')

@section('title', $invoice->invoice_number.' · Print')
@section('section', 'Invoices & Billing')

@section('content')
    <div class="page-heading invoice-print-controls"><div><span class="eyebrow">PRINTABLE NON-TAX PAYMENT RECEIPT</span><h1>{{ $invoice->invoice_number }}</h1></div><div class="toolbar-actions"><a class="button button-secondary" href="{{ route('super-admin.invoices.show', $invoice) }}">Back</a><button class="button button-primary" type="button" data-print-invoice>Print receipt</button></div></div>
    @include('super-admin.invoices._receipt-details', ['invoice' => $invoice])
@endsection
