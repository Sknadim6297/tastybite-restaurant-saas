@php($formattedAuditDetails = \App\Support\AuditLogDetails::format($details ?? null))
@if($formattedAuditDetails === [])
    <span class="audit-details-empty">No details recorded</span>
@else
    <dl class="audit-details-list">
        @foreach($formattedAuditDetails as $detail)
            <div><dt>{{ $detail['label'] }}</dt><dd>{{ $detail['value'] }}</dd></div>
        @endforeach
    </dl>
@endif
