<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;

class NotesTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('notes');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');
        $this->belongsTo('Users', ['foreignKey' => 'user_id']);
        $this->belongsToMany('Tags');
    }
}
