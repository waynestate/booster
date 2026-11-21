<h1>Note <?= h($id) ?></h1>
<?= $this->Form->postLink('Delete', ['action' => 'delete', $id], ['confirm' => 'Sure?']) ?>
