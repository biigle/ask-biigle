<li>
    <a href="#" onclick="event.preventDefault(); window.dispatchEvent(new CustomEvent('ask-biigle:open'));" title="Ask BIIGLE">Ask BIIGLE ✨</a>
    <div id="ask-biigle-container"></div>
</li>

@once
    @push('scripts')
        {{vite_hot(base_path('vendor/biigle/ask-biigle/hot'), ['src/resources/assets/js/chatbot.js'], 'vendor/ask-biigle')}}
    @endpush
@endonce
