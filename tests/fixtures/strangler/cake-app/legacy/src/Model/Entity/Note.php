<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class Note extends Entity
{
    protected array $_accessible = [
        'title' => true,
        'body' => true,
        'id' => false,
    ];

    protected array $_hidden = ['secret'];
}
