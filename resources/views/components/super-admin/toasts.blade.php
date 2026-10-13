<div class="toast-region" data-toast-region aria-label="Notifications" aria-live="polite" aria-relevant="additions text">
    @if (session('status'))
        @php($toastType = in_array(session('toast_type'), ['success', 'warning', 'information'], true) ? session('toast_type') : 'success')
        <div class="toast toast-{{ $toastType }}" role="status" data-toast data-autodismiss="true">
            <span class="toast-symbol" aria-hidden="true">{{ $toastType === 'success' ? '✓' : ($toastType === 'warning' ? '!' : 'i') }}</span>
            <p>{{ session('status') }}</p>
            <button type="button" class="toast-close" data-toast-close aria-label="Dismiss notification">×</button>
        </div>
    @endif
    @foreach (collect($errors->all())->unique() as $message)
        <div class="toast toast-error" role="alert" data-toast>
            <span class="toast-symbol" aria-hidden="true">!</span>
            <p>{{ $message }}</p>
            <button type="button" class="toast-close" data-toast-close aria-label="Dismiss notification">×</button>
        </div>
    @endforeach
</div>
