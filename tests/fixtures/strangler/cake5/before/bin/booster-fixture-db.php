<?php

// The strangler proof's fixture database: the CMS tutorial's schema (from
// config/Migrations) in SQLite at the path app_local.example.php names, with
// fixed rows and fixed dates so every replay answer is the same. The one user's
// password is a fixture value, never a real one. Run from the app root.

$path = __DIR__ . '/../cms-tutorial-database.sqlite';
@unlink($path);
$db = new PDO('sqlite:' . $path);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, email VARCHAR(255) NOT NULL, password VARCHAR(255) NOT NULL, created DATETIME, modified DATETIME)');
$db->exec('CREATE TABLE articles (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, title VARCHAR(255) NOT NULL, slug VARCHAR(191) NOT NULL UNIQUE, body TEXT, published BOOLEAN DEFAULT 0, created DATETIME, modified DATETIME)');
$db->exec('CREATE TABLE tags (id INTEGER PRIMARY KEY AUTOINCREMENT, title VARCHAR(191) NOT NULL UNIQUE, created DATETIME, modified DATETIME)');
$db->exec('CREATE TABLE articles_tags (article_id INTEGER NOT NULL, tag_id INTEGER NOT NULL, PRIMARY KEY (article_id, tag_id))');
$when = '2026-01-02 03:04:05';
$user = $db->prepare('INSERT INTO users (email, password, created, modified) VALUES (?, ?, ?, ?)');
$user->execute(['fixture@example.edu', password_hash('fixture-only', PASSWORD_DEFAULT), $when, $when]);
$article = $db->prepare('INSERT INTO articles (user_id, title, slug, body, published, created, modified) VALUES (1, ?, ?, ?, 1, ?, ?)');
$article->execute(['First Post', 'first-post', 'This is the first post.', $when, $when]);
$article->execute(['Second Post', 'second-post', 'This is the second post.', $when, $when]);
$db->exec("INSERT INTO tags (title, created, modified) VALUES ('funny', '$when', '$when')");
$db->exec('INSERT INTO articles_tags (article_id, tag_id) VALUES (1, 1)');
