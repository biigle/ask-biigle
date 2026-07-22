<li>
    <a href="#" onclick="event.preventDefault(); window.dispatchEvent(new CustomEvent('biiglebot:open'));" title="BIIGLEBot" class="navbar-btn-link">
        <span class="btn btn-default">
            <i class="fa fa-comments"></i>
        </span>
    </a>
    <div id="biiglebot-chatbot-container"></div>
</li>

@once
    @push('scripts')
        {{vite_hot(base_path('vendor/biigle/biiglebot/hot'), ['src/resources/assets/js/chatbot.js'], 'vendor/BIIGLEBot')}}
    @endpush
@endonce
