<?php
// Notes: a tiny raw-PHP app for Booster's replay gate tests. Fake data only,
// one SQLite file under /tmp built by replay/seed.php. It has just enough to
// exercise the gate: a page with a CSRF-protected form, a save that redirects,
// a JSON read-back, an error page, a 405, a 404, and one answer PHP 8 changes
// (the string-to-number comparison). Parses on PHP 5.6 and up.

function notes_db()
{
    $file = getenv('NOTES_DB') ? getenv('NOTES_DB') : '/tmp/notes.sqlite';
    $pdo = new PDO('sqlite:' . $file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

function notes_html($status, $title, $body)
{
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');
    echo "<!doctype html>\n<html lang=\"en\">\n<head><title>" . htmlspecialchars($title) . "</title></head>\n<body>\n";
    echo $body;
    echo "\n<footer>Page built at " . date('Y-m-d H:i:s') . "</footer>\n</body>\n</html>\n";
}

function notes_json($status, $data)
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
}

function notes_token()
{
    if (empty($_SESSION['token'])) {
        $_SESSION['token'] = md5(uniqid('notes', true) . mt_rand());
    }
    return $_SESSION['token'];
}

function notes_dispatch($method, $path)
{
    session_start();
    if ($path === '/' && $method === 'GET') {
        $items = '';
        foreach (notes_db()->query('SELECT id, title FROM notes ORDER BY id') as $row) {
            $items .= '  <li><a href="/notes/' . (int) $row['id'] . '">' . htmlspecialchars($row['title']) . "</a></li>\n";
        }
        notes_html(200, 'Notes', "<h1>Notes</h1>\n<ul>\n" . $items . "</ul>\n"
            . "<form method=\"post\" action=\"/notes\">\n"
            . '  <input type="hidden" name="_token" value="' . notes_token() . "\">\n"
            . "  <label>Title <input name=\"title\"></label>\n  <button>Save</button>\n</form>");
        return;
    }
    if ($path === '/notes') {
        if ($method !== 'POST') {
            header('Allow: POST');
            notes_html(405, 'Method not allowed', '<h1>Method not allowed</h1>');
            return;
        }
        if (!isset($_POST['_token']) || $_POST['_token'] !== notes_token()) {
            notes_html(419, 'Page expired', '<h1>Page expired</h1>');
            return;
        }
        $title = isset($_POST['title']) ? trim($_POST['title']) : '';
        if ($title === '') {
            notes_html(422, 'Title missing', '<h1>A note needs a title</h1>');
            return;
        }
        $insert = notes_db()->prepare('INSERT INTO notes (title, score, created) VALUES (?, ?, ?)');
        $insert->execute(array($title, 3, date('Y-m-d H:i:s')));
        $id = (int) notes_db()->query('SELECT MAX(id) FROM notes')->fetchColumn();
        setcookie('last_note', (string) $id, 0, '/');
        header('Location: /notes/' . $id, true, 302);
        return;
    }
    if (preg_match('#^/notes/(\d+)$#', $path, $m) && $method === 'GET') {
        $select = notes_db()->prepare('SELECT id, title, score, created FROM notes WHERE id = ?');
        $select->execute(array((int) $m[1]));
        $row = $select->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            notes_json(404, array('error' => 'No such note'));
            return;
        }
        notes_json(200, array('id' => (int) $row['id'], 'title' => $row['title'], 'score' => (float) $row['score'], 'created' => $row['created']));
        return;
    }
    if ($path === '/compare' && $method === 'GET') {
        $code = isset($_GET['code']) ? $_GET['code'] : '';
        // PHP 8 compares a number with a non-numeric string as strings, so this
        // answer changes on the PHP 7.4 to 8.0 hop. That is the point.
        notes_json(200, array('code' => $code, 'matches_zero' => 0 == $code));
        return;
    }
    if ($path === '/boom') {
        notes_html(500, 'Something went wrong', '<h1>Something went wrong</h1>');
        return;
    }
    notes_html(404, 'Not found', '<h1>Not found</h1>');
}
