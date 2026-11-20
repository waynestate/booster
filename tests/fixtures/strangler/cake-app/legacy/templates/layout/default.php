<?php
/**
 * The fixture layout.
 */
$description = 'Notes';
?>
<!DOCTYPE html>
<html>
<head>
    <?= $this->Html->charset() ?>
    <title><?= $description ?>: <?= $this->fetch('title') ?></title>
    <?= $this->Html->meta('icon') ?>
    <?= $this->Html->css(['base', 'notes.css']) ?>
    <?= $this->fetch('css') ?>
</head>
<body>
    <nav><?= $this->Html->link('Home', ['controller' => 'Notes', 'action' => 'index'], ['class' => 'brand']) ?> mail us at notes@example.edu {{ not blade }}</nav>
    <?= $this->Flash->render() ?>
    <main><?= $this->fetch('content') ?></main>
</body>
</html>
