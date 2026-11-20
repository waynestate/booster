<?php
declare(strict_types=1);

namespace App\Controller;

use DateTimeImmutable;

class NotesController extends AppController
{
    public function index()
    {
        $this->set('notes', ['First', 'Second <b>']);
        $this->set('who', $this->Authentication->getIdentity()?->get('name'));
    }

    public function add()
    {
        if ($this->request->is('post')) {
            $title = trim((string) $this->request->getData('title'));
            if ($title === '') {
                $this->Flash->error(__('A note needs a title.'));

                return $this->redirect(['action' => 'add']);
            }
            $this->Flash->success(__('Saved.'));

            return $this->redirect(['action' => 'index']);
        }
        $this->set('title', (string) $this->request->getQuery('title', ''));
    }

    public function hello($name = 'world')
    {
        $year = (new DateTimeImmutable('2026-11-20'))->format('Y');
        $this->set(compact('name', 'year'));
    }

    public function me()
    {
        $this->set('user', $this->Authentication->getIdentityData('name'));
    }

    public function report()
    {
        $rows = $this->Notes->find()->all();
        $this->set(compact('rows'));
    }
}
