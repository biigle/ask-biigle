@extends('app')
@section('title', 'Inspiring quotes')
@section('content')
<div id="quotes-container" class="container">
   <div class="col-sm-8 col-sm-offset-2 col-lg-6 col-lg-offset-3">
      <blockquote v-html="quote"></blockquote>
      <button class="btn btn-default" v-on:click="refreshQuote">refresh</button>
   </div>
</div>
@endsection

@push('scripts')
{{vite_hot(base_path('vendor/biigle/module/hot'), ['src/resources/assets/js/main.js'], 'vendor/module')}}
@endpush
@push('styles')
{{vite_hot(base_path('vendor/biigle/module/hot'), ['src/resources/assets/sass/main.scss'], 'vendor/module')}}
@endpush
