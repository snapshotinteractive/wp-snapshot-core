@php($sentence = \Snapshot\Remote::override($area))

@if ($sentence !== '')
  <div class="notice notice-info inline"><p>{{ $sentence }}</p></div>
@endif
