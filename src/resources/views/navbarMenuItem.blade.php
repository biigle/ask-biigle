@once
    @push('scripts')
        {{vite_hot(base_path('vendor/biigle/BIIGLEBot/hot'), ['src/resources/assets/js/chatbot.js'], 'vendor/BIIGLEBot')}}
    @endpush
@endonce
