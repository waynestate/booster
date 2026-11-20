<?php $this->assign('title', 'All notes'); ?>
<h1>Notes<?php if ($who !== null): ?> for <?= h($who) ?><?php endif; ?></h1>
<ul>
<?php foreach ($notes as $note): ?>
    <li><?= h($note) ?></li>
<?php endforeach; ?>
</ul>
<p><?= $this->Html->link(__('Add a note'), ['action' => 'add']) ?></p>
