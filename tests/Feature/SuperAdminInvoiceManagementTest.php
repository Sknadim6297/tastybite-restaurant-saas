<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\SuperAdmin;
use App\Services\InvoiceManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminInvoiceManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_routes_require_super_admin_authorization(): void
    {
        $this->get(route('super-admin.invoices.index'))
            ->assertRedirect(route('super-admin.login'));

        $this->actingAsSuperAdmin();
        $this->get(route('super-admin.invoices.index'))->assertOk()->assertSee('No receipts found');
        $this->get(route('super-admin.invoices.export'))->assertOk();
    }

    public function test_approved_payment_issues_one_non_tax_receipt_and_pdf_and_print_are_available(): void
    {
        $admin = $this->actingAsSuperAdmin();
        $payment = $this->createPendingPayment($admin);

        $this->assertDatabaseCount('subscription_invoices', 0);
        $this->post(route('super-admin.payments.approve', $payment))->assertRedirect();

        $invoice = SubscriptionInvoice::query()->firstOrFail();
        $this->assertSame('non_tax_payment_receipt', $invoice->document_type);
        $this->assertSame('INR 1200.00', 'INR '.$invoice->total);
        $this->assertSame($payment->id, $invoice->subscription_payment_id);
        $this->assertNotNull($invoice->restaurant_subscription_id);

        $this->get(route('super-admin.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('Non-tax payment receipt')
            ->assertSee('Tax registration details')
            ->assertSee($invoice->invoice_number);
        $this->get(route('super-admin.invoices.print', $invoice))
            ->assertOk()
            ->assertSee('data-print-invoice', false);
        $pdf = $this->get(route('super-admin.invoices.pdf', $invoice))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $same = app(InvoiceManagementService::class)->issueForApprovedPayment(
            $payment->fresh(),
            $payment->fresh()->subscription()->firstOrFail(),
            $admin,
        );
        $this->assertSame($invoice->id, $same->id);
        $this->assertDatabaseCount('subscription_invoices', 1);
        $this->assertSame(1, $payment->fresh()->subscription()->count());
    }

    public function test_refund_records_change_net_receipt_totals_without_claiming_external_return(): void
    {
        $this->actingAsSuperAdmin();
        $payment = $this->createPendingPayment($this->admin(), ['notes' => 'DEMO DATA ONLY - test fixture']);
        $this->post(route('super-admin.payments.approve', $payment))->assertRedirect();
        $invoice = SubscriptionInvoice::query()->firstOrFail();

        $this->post(route('super-admin.payments.refunds.store', $payment), [
            'amount' => '200.00',
            'method' => 'upi',
            'reference' => 'INVOICE-REFUND-1',
            'refunded_at' => now()->format('Y-m-d\TH:i'),
            'reason' => 'Verified manual refund record.',
        ])->assertRedirect();
        $this->assertSame('200.00', $invoice->fresh()->recordedRefundAmount());
        $this->assertSame('1000.00', $invoice->fresh()->netRetainedAmount());

        $this->post(route('super-admin.payments.refunds.store', $payment), [
            'amount' => '1000.00',
            'method' => 'cash',
            'reference' => 'INVOICE-REFUND-2',
            'refunded_at' => now()->format('Y-m-d\TH:i'),
            'reason' => 'Remaining amount recorded for a demo test.',
        ])->assertRedirect();

        $this->assertSame('1200.00', $invoice->fresh()->recordedRefundAmount());
        $this->assertSame('0.00', $invoice->fresh()->netRetainedAmount());
        $this->get(route('super-admin.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('DEMO ONLY — NOT ACTUAL MONEY')
            ->assertSee('do not confirm that funds were returned');
    }

    public function test_invoice_search_status_date_filters_and_csv_export_use_the_same_records(): void
    {
        $this->actingAsSuperAdmin();
        $payment = $this->createPendingPayment($this->admin(), ['transaction_reference' => 'INVOICE-SEARCH-77']);
        $this->post(route('super-admin.payments.approve', $payment))->assertRedirect();
        $invoice = SubscriptionInvoice::query()->firstOrFail();

        $this->get(route('super-admin.invoices.index', [
            'search' => 'INVOICE-SEARCH-77',
            'status' => 'approved',
            'date_from' => $invoice->issue_date->timezone(config('app.timezone'))->toDateString(),
            'date_to' => $invoice->issue_date->timezone(config('app.timezone'))->toDateString(),
        ]))
            ->assertOk()
            ->assertSee($invoice->invoice_number)
            ->assertSee('INVOICE-SEARCH-77');

        $export = $this->get(route('super-admin.invoices.export', ['status' => 'approved']))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString($invoice->invoice_number, $export->streamedContent());
        $this->assertStringContainsString('INVOICE-SEARCH-77', $export->streamedContent());

        $this->get(route('super-admin.invoices.index', ['status' => 'refunded']))
            ->assertOk()
            ->assertSee('No receipts found');
    }

    public function test_pending_or_rejected_payments_do_not_receive_receipts(): void
    {
        $admin = $this->actingAsSuperAdmin();
        $pending = $this->createPendingPayment($admin);
        $this->assertDatabaseMissing('subscription_invoices', ['subscription_payment_id' => $pending->id]);

        $this->post(route('super-admin.payments.reject', $pending), ['reason' => 'Payment reference could not be verified.'])
            ->assertRedirect();
        $this->assertDatabaseMissing('subscription_invoices', ['subscription_payment_id' => $pending->id]);
        $this->assertDatabaseCount('subscription_invoices', 0);
    }

    private function actingAsSuperAdmin(): SuperAdmin
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'super_admin');
        $this->app['session']->put('super_admin_password_version', hash('sha256', $admin->getAuthPassword()));

        return $admin;
    }

    private function admin(): SuperAdmin
    {
        return SuperAdmin::query()->firstOrCreate(
            ['email' => 'invoice-admin@example.test'],
            ['name' => 'Invoice Test Admin', 'password' => 'StrongAdminPass123!', 'is_active' => true],
        );
    }

    private function createPendingPayment(SuperAdmin $admin, array $overrides = []): SubscriptionPayment
    {
        $restaurant = Restaurant::create([
            'name' => 'Invoice Demo Restaurant',
            'slug' => 'invoice-demo-'.str()->random(8),
            'email' => 'invoice-'.str()->random(8).'@example.test',
            'status' => 'active',
        ]);
        $plan = SubscriptionPlan::create([
            'name' => 'Invoice Plan',
            'code' => 'INV-'.strtoupper(str()->random(8)),
            'price' => '1200.00',
            'currency' => 'INR',
            'billing_period' => 'monthly',
            'duration_months' => 1,
            'status' => 'active',
        ]);

        return SubscriptionPayment::create(array_merge([
            'restaurant_id' => $restaurant->id,
            'subscription_plan_id' => $plan->id,
            'created_by' => $admin->id,
            'plan_name' => $plan->name,
            'plan_code' => $plan->code,
            'amount' => '1200.00',
            'currency' => 'INR',
            'payment_method' => 'upi',
            'transaction_reference' => 'INVOICE-'.str()->random(10),
            'status' => 'pending',
            'paid_at' => now(),
        ], $overrides));
    }
}
