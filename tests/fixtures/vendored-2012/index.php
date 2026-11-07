<?php
// The front controller, as written in 2012.
require_once 'vendor/Doctrine/ORM/Tools/Setup.php';
use Doctrine\ORM\Tools\Setup;
Setup::registerAutoloadDirectory('vendor');

define('SMARTY_DIR', __DIR__ . '/vendor/Smarty/');
require SMARTY_DIR . 'Smarty.class.php';
require "vendor/Slim/Slim.php";
require 'lib/SmartyView.php';
require 'lib/helpers.php';

$app = new Slim(array(
    'mode' => 'production',
    'view' => 'SmartyView',
));
$app->em = bootstrap_entity_manager();

$app->get('/', function () use ($app) {
    $app->render('home.tpl');
});

$app->get('/batches/:id', function ($id) use ($app) {
    $batch = $app->em->find('Batch', $id);
    if (!$batch) {
        $app->notFound();
    }
    $app->render('batch.tpl', array('batch' => $batch));
});

$app->post('/batches/:id', function ($id) use ($app) {
    // $app->flash('info', 'Saved'); came out in 2013, so this is only a comment.
    $app->redirect('/batches/' . $id);
});

$app->run();
