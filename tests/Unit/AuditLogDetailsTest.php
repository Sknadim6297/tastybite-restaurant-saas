<?php

namespace Tests\Unit;

use App\Support\AuditLogDetails;
use PHPUnit\Framework\TestCase;

class AuditLogDetailsTest extends TestCase
{
    public function test_formats_labels_and_omits_sensitive_values(): void
    {
        $details = AuditLogDetails::format([
            'restaurant_name' => 'Royal Bengal Kitchen',
            'owner_email' => 'owner@example.test',
            'duration_months' => 3,
            'credentials' => ['password' => 'secret'],
            'nested' => ['api_token' => 'also-secret', 'is_active' => true],
        ]);

        $this->assertSame([
            ['label' => 'Restaurant', 'value' => 'Royal Bengal Kitchen'],
            ['label' => 'Owner Email', 'value' => 'owner@example.test'],
            ['label' => 'Duration Months', 'value' => '3'],
            ['label' => 'Nested · Is Active', 'value' => 'Yes'],
        ], $details);
    }

    public function test_null_empty_and_malformed_details_are_safe(): void
    {
        $this->assertSame([], AuditLogDetails::format(null));
        $this->assertSame([], AuditLogDetails::format(''));
        $this->assertSame([], AuditLogDetails::format('{"broken":'));
        $this->assertSame([], AuditLogDetails::format('[]'));
        $this->assertSame([
            ['label' => 'Details', 'value' => 'Legacy audit note'],
        ], AuditLogDetails::format('Legacy audit note'));
    }
}
