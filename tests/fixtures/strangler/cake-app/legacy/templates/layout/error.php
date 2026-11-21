<!DOCTYPE html>
<html>
<head>
    <?= $this->Html->charset() ?>
    <title><?= $this->fetch('title') ?></title>
</head>
<body>
    <h1><?= __('Error') ?></h1>
    <?= $this->Flash->render() ?>
    <?= $this->fetch('content') ?>
    <?= $this->Html->link(__('Back'), 'javascript:history.back()') ?>
</body>
</html>
