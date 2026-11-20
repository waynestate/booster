<h1>Add a note</h1>
<form method="post" action="<?= $this->Url->build(['action' => 'add']) ?>">
    <input name="title" value="<?= h($title) ?>">
    <button>Save</button>
</form>
