<li>
    <a href="#" onclick="event.preventDefault(); window.dispatchEvent(new CustomEvent('askbiigle:open'));" title="askBIIGLE" class="navbar-btn-link">
        <span class="btn btn-default">
            <i class="fa fa-comments"></i>
        </span>
    </a>
    <div id="askbiigle-chatbot-container"></div>
</li>

@once
    @push('scripts')
        {{vite_hot(base_path('vendor/biigle/askbiigle/hot'), ['src/resources/assets/js/chatbot.js'], 'vendor/askBIIGLE')}}
    @endpush
@endonce
