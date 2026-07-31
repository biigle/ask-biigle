@once
    @push('scripts')
        {{vite_hot(base_path('vendor/biigle/biiglebot/hot'), ['src/resources/assets/js/chatbot.js'], 'vendor/biiglebot')}}
    @endpush
@endonce
