<li>
    <a href="#" onclick="event.preventDefault(); window.dispatchEvent(new CustomEvent('ask-biigle:open'));" title="Ask BIIGLE" class="navbar-btn-link">
        <span class="btn btn-default">
            <i class="fa fa-comments"></i>
        </span>
    </a>
    <div id="ask-biigle-container"></div>
</li>

@once
    @push('scripts')
        {{vite_hot(base_path('vendor/biigle/ask-biigle/hot'), ['src/resources/assets/js/chatbot.js'], 'vendor/ask-biigle')}}
    @endpush
@endonce
