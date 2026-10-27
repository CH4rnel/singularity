{{--
    The session recorder (resources/js/replay/recorder.ts), behind App\Support\SessionReplay:
    who is never recorded is decided on the server, the visitor's own refusal in the browser.
    Recordings go to this server's /api/replay and nowhere else.
--}}
@php($replay = \App\Support\SessionReplay::for(request()))
@if ($replay)
<script defer @foreach ($replay as $attribute => $value) {{ $attribute }}="{{ $value }}" @endforeach></script>
@endif
