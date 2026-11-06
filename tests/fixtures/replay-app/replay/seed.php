<?php
// Builds the fake data for a replay: a fresh SQLite file with two notes.
// Booster runs it inside the container before it starts the server.
$file = getenv('NOTES_DB') ? getenv('NOTES_DB') : '/tmp/notes.sqlite';
if (is_file($file)) {
    unlink($file);
}
$pdo = new PDO('sqlite:' . $file);
$pdo->exec('CREATE TABLE notes (id INTEGER PRIMARY KEY, title TEXT NOT NULL, score REAL NOT NULL, created TEXT NOT NULL)');
$pdo->exec("INSERT INTO notes (title, score, created) VALUES ('Buy more coffee', 4, '2026-01-02 09:00:00')");
$pdo->exec("INSERT INTO notes (title, score, created) VALUES ('Water the office fern', 2.5, '2026-01-03 10:30:00')");
echo "seeded 2 notes into $file\n";
