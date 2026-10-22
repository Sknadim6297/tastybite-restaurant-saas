<?php

namespace Tests\Unit;

use App\Support\StatusPresenter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StatusPresenterTest extends TestCase
{
    #[DataProvider('statusLabels')]
    public function test_status_labels_are_human_readable(?string $status, string $expected): void
    {
        $this->assertSame($expected, StatusPresenter::label($status));
    }

    public static function statusLabels(): array
    {
        return [
            'pending payment' => ['pending_payment', 'Pending Payment'],
            'pending approval' => ['pending_approval', 'Pending Approval'],
            'simple status' => ['active', 'Active'],
            'null status' => [null, '—'],
            'empty status' => ['', '—'],
        ];
    }

    #[DataProvider('statusBadgeClasses')]
    public function test_status_badge_classes_follow_status_meaning(?string $status, string $expected): void
    {
        $this->assertSame($expected, StatusPresenter::badgeClass($status));
    }

    public static function statusBadgeClasses(): array
    {
        return [
            'pending status' => ['pending_approval', 'pending'],
            'active status' => ['active', 'active'],
            'paid status' => ['paid', 'active'],
            'rejected status' => ['rejected', 'rejected'],
            'cancelled status' => ['cancelled', 'rejected'],
            'expired status' => ['expired', 'disabled'],
            'unpaid status' => ['unpaid', 'pending'],
            'partially paid status' => ['partially_paid', 'pending'],
            'open ticket status' => ['open', 'active'],
            'suspended status' => ['suspended', 'suspended'],
            'partially paid label' => ['Partially Paid', 'pending'],
            'fully paid label' => ['Fully Paid', 'active'],
            'draft announcement status' => ['draft', 'pending'],
            'null status' => [null, 'disabled'],
        ];
    }
}
