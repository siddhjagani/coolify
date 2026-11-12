<x-emails.layout>
{{ $summary }}

@foreach ($dnsLines as $line)
{{ $line }}

@endforeach
@if ($applicationUrl)
[Open the application]({{ $applicationUrl }}).
@endif
</x-emails.layout>
