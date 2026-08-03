<?php

$router->post('ask-biigle/chat', [
   'middleware' => 'auth',
   'as' => 'ask-biigle.chat',
   'uses' => 'ChatController@chat',
]);
