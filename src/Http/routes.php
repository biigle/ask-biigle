<?php

$router->post('askbiigle/chat', [
   'middleware' => 'auth',
   'as' => 'askbiigle.chat',
   'uses' => 'ChatController@chat',
]);
