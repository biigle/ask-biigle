@once
    @push('scripts')
        {{vite_hot(base_path('vendor/biigle/BIIGLEBot/hot'), ['src/resources/assets/js/chatbot.js'], 'vendor/module')}}
    @endpush
    @push('styles')
        {{vite_hot(base_path('vendor/biigle/BIIGLEBot/hot'), ['src/resources/assets/sass/chatbot.scss'], 'vendor/module')}}
    @endpush
@endonce
