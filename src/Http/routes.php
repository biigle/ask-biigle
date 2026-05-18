<?php

$router->post('biiglebot/chat', [
   'middleware' => 'auth',
   'as' => 'biiglebot.chat',
   'uses' => 'ChatController@chat',
]);
