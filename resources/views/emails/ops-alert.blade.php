{{ $alertMessage }}

Alert: {{ $alertKey }}
Environment: {{ config('app.env') }}
Time: {{ now()->toIso8601String() }}

@foreach ($context as $key => $value)
{{ $key }}: {{ is_scalar($value) ? $value : json_encode($value) }}
@endforeach

Search error tracking and the logs for the correlation id above to follow the request and its jobs.
Repeats of this alert are muted for {{ config('observability.alerts.throttle_minutes') }} minutes.
