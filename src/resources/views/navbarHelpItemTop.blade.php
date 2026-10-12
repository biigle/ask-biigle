<li>
    <a href="#" onclick="event.preventDefault(); window.dispatchEvent(new CustomEvent('ask-biigle:open'));" title="Ask BIIGLE">Ask BIIGLE ✨</a>
    {{-- The user ID scopes the stored conversation to the current user. --}}
    <div id="ask-biigle-container" data-user-id="{{ auth()->id() }}"></div>
</li>

@once
    @push('scripts')
        {{-- Must be declared before the chatbot module is loaded below. --}}
        <script type="module">
            biigle.$declare('askBiigle.adminEmail', @json(config('biigle.admin_email')));
            @if (View::exists('privacy'))
                {{-- The questions are sent to an external AI service, so users have to confirm the privacy notice once. --}}
                biigle.$declare('askBiigle.privacyUrl', @json(route('privacy')));
                biigle.$declare('askBiigle.confirmedPrivacy', @json((bool) auth()->user()?->getSettings('ask_biigle_confirmed_privacy', false)));
            @endif
        </script>
        {{vite_hot(base_path('vendor/biigle/ask-biigle/hot'), ['src/resources/assets/js/chatbot.js'], 'vendor/ask-biigle')}}
    @endpush
@endonce
