<?php

$router->get('quotes', [
   'middleware' => 'auth',
   'as'   => 'quotes',
   'uses' => 'QuotesController@index',
]);

$router->get('quotes/new', [
   'middleware' => 'auth',
   'uses' => 'QuotesController@quote',
]);

$router->post('biiglebot/chat', [
   'middleware' => 'auth',
   'as' => 'biiglebot.chat',
   'uses' => 'ChatController@chat',
]);
