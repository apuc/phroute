<?php
error_reporting(-1);
ini_set('display_errors', 1);
include __DIR__ . '/../vendor/autoload.php';

use Phroute\Phroute\RouteCollector;
use Phroute\Phroute\Dispatcher;

$collector = new RouteCollector();

$collector->get('/', function(){
    return 'Home Page';
});

$collector->post('products', function(){
    return 'Create Product';
});

$collector->put('items/{id}', function($id){
    return 'Amend Item ' . $id;
});

$dispatcher =  new Dispatcher($collector->getData());

echo $dispatcher->dispatch($_SERVER['REQUEST_METHOD'], parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));   // Home Page
//echo $dispatcher->dispatch('POST', '/products'), "\n"; // Create Product
//echo $dispatcher->dispatch('PUT', '/items/123'), "\n"; // Amend Item 123
