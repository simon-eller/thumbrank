<?php

// Start PHP session and specify user id
session_start();
$user_id = session_id();

// Enviromental variables
$default_lang = "de";

// Gettext stuff
$current_user_lang = $_GET["lang"] ?? $_SESSION["lang"] ?? $default_lang;

$locales = [
    "de" => "de_DE.UTF-8",
    "en" => "en_US.UTF-8"
];

if (array_key_exists($current_user_lang, $locales)) {
    // Store lang in current users session
    $_SESSION["lang"] = $current_user_lang;
} else {
    // Fallback to default lang
    $current_user_lang = $default_lang;
}

$system_locale = $locales[$current_user_lang];

// Set environment variables for gettext
putenv("LC_ALL=$system_locale");
setlocale(LC_ALL, $system_locale);

// Specify location of translation tables
bindtextdomain("messages", __DIR__ . "/locale");
bind_textdomain_codeset("messages", "UTF-8");

// Set domain
textdomain("messages");

// Remove lang parameter from url after lang is stored in session
if (isset($_GET["lang"])) {
    $params = $_GET; unset($params["lang"]);
    $q = http_build_query($params);
    header("Location: " . $_SERVER["PHP_SELF"] . ($q ? "?".$q : ""));
    exit;
}

// Set path to sqlite database
$dbFile = __DIR__ . "/thumbrank.db";

// Connect to database
try {
    $pdo = new PDO("sqlite:" . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("PRAGMA foreign_keys = ON");

    // Create tables if not existing yet
    $pdo->exec("CREATE TABLE IF NOT EXISTS rooms (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        room_code TEXT UNIQUE,
        name TEXT,
        creator_session_id TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS videos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        youtube_id TEXT UNIQUE,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS room_videos (
        room_id INTEGER,
        video_id INTEGER,
        submitted_by_session_id TEXT,
        PRIMARY KEY (room_id, video_id),
        FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
        FOREIGN KEY (video_id) REFERENCES videos(id) ON DELETE CASCADE
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS votes (
        room_id INTEGER,
        video_id INTEGER,
        user_session_id TEXT,
        vote_value INTEGER, -- 1 = Like, 0 = Dislike,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (room_id, video_id, user_session_id),
        FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
        FOREIGN KEY (video_id) REFERENCES videos(id) ON DELETE CASCADE
    )");

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Extract YouTube video id from YouTube link
function get_yt_video_id($youtube_video_url) {
    $pattern = '%(?:youtube(?:-nocookie)?\.com/(?:[^/]+/.+/|(?:v|e(?:mbed)?)/|.*[?&]v=)|youtu\.be/)([^"&?/ ]{11})%i';

    if (preg_match($pattern, $youtube_video_url, $match)) {
        return $match[1];
    }

    // if no match return false.
    return false;
}

// Room logic
$room_code = $_GET["room"] ?? null;
$room = null;
$is_room_owner = false;

// Create new room
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["create_room"])) {
    $new_code = bin2hex(random_bytes(3)); // z.B. a1b2c3
    $name = trim($_POST["room_name"]) ?: "unnamed room";

    $stmt = $pdo->prepare("INSERT INTO rooms (room_code, name, creator_session_id) VALUES (?, ?,?)");
    $stmt->execute([$new_code, $name, $user_id]);

    header("Location: ?room=" . $new_code);
    exit;
}

// Do actions in room
if ($room_code) {

    // Load room from database
    $stmt = $pdo->prepare("SELECT * FROM rooms WHERE room_code = ?");
    $stmt->execute([$room_code]);
    $room = $stmt->fetch();

    if ($room) {
        // Check if current user is owner of room
        $is_room_owner = ($room["creator_session_id"] === $user_id);

        // If form to delete room was submitted
        if ($is_room_owner && isset($_POST["delete_room"])) {
            $stmt = $pdo->prepare("DELETE FROM rooms WHERE id = ?");
            $stmt->execute([$room["id"]]);
            header("Location: ./");
            exit;
        }

        // If form to add new video was submitted
        if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["add_video"])) {
            $yt_video_id = get_yt_video_id($_POST["yt_video_url"]);

            if ($yt_video_id) {
                // Add dataset for video to database
                $stmt = $pdo->prepare("INSERT OR IGNORE INTO videos (youtube_id) VALUES (?)");
                $stmt->execute([$yt_video_id]);

                // Get dataset of video from database
                $stmt = $pdo->prepare("SELECT id FROM videos WHERE youtube_id = ?");
                $stmt->execute([$yt_video_id]);
                $vid_row = $stmt->fetch();

                // Connect video dataset to current room
                $stmt = $pdo->prepare("INSERT OR IGNORE INTO room_videos (room_id, video_id, submitted_by_session_id) VALUES (?, ?, ?)");
                $stmt->execute([$room["id"], $vid_row["id"], $user_id]);
            }
        }

        // If form to delete video was submitted--
        if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["delete_video"])) {
            $vid_to_delete = $_POST["video_id"];

            // Allow deleting when current user is room creator or added video
            $checkSql = "DELETE FROM room_videos WHERE room_id = ? AND video_id = ? AND (submitted_by_session_id = ? OR ?)";
            $stmt = $pdo->prepare($checkSql);
            $stmt->execute([$room["id"], $vid_to_delete, $user_id, $is_room_owner ? 1 : 0]);
        }

        // Add dataset for downvote or upvote for current video to database
        if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["vote"])) {
            $video_id = $_POST["video_id"];
            $val = (int)$_POST["vote_value"]; // 1 oder 0

            $stmt = $pdo->prepare("INSERT OR REPLACE INTO votes (room_id, video_id, user_session_id, vote_value) VALUES (?, ?, ?, ?)");
            $stmt->execute([$room["id"], $video_id, $user_id, $val]);
        }
    }
}

// Load datasets of database to display
$videos = [];
if ($room) {
    // Load videos, video voting stats and votes of current user from database and sort it most popular first
    $sql = "SELECT v.*, rv.submitted_by_session_id,
            SUM(CASE WHEN vt.vote_value = 1 THEN 1 ELSE 0 END) as likes,
            SUM(CASE WHEN vt.vote_value = 0 THEN 1 ELSE 0 END) as dislikes,
            (SELECT vote_value FROM votes WHERE room_id = r.id AND video_id = v.id AND user_session_id = ?) as current_user_vote
            FROM videos v
            JOIN room_videos rv ON v.id = rv.video_id
            JOIN rooms r ON rv.room_id = r.id
            LEFT JOIN votes vt ON v.id = vt.video_id AND vt.room_id = r.id
            WHERE r.id = ?
            GROUP BY v.id
            ORDER BY likes DESC, dislikes ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id, $room["id"]]);
    $videos = $stmt->fetchAll();
}
?>

<!DOCTYPE html>
<html lang="<?php echo $current_user_lang; ?>">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>ThumbRank <?php echo $room ? "- " . htmlspecialchars($room["name"]) : ""; ?></title>

        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,1,0&icon_names=close,favorite,globe,thumb_down,thumb_up,thumbs_up_down" />

        <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 -960 960 960%22 fill=%22%231f1f1f%22><path d=%22M80-400q-33 0-56.5-23.5T0-480v-240q0-12 5-23t13-19l126-126q9-9 20-13.5t22-4.5q26 0 45 20t14 51l-13 75h208q17 0 28.5 11.5T480-720v50q0 6-1 11.5t-3 10.5l-90 212q-7 17-22.5 26.5T330-400H80Zm440 200q-17 0-28.5-11.5T480-240v-50q0-6 1-11.5t3-10.5l90-212q8-17 23-26.5t33-9.5h250q33 0 56.5 23.5T960-480v240q0 12-4.5 22.5T942-198L816-72q-9 9-20 13.5T774-54q-26 0-45-20t-14-51l13-75H520Z%22/></svg>">

        <style>
            .card{
                transition: transform 0.2s;
            }
            .card:hover{
                transform: translateY(-2px);
            }

            .btn-delete-video {
                position: absolute; top: 8px; right: 8px; z-index: 10;
                width: 25px; height: 25px; display: flex;
                align-items: center; justify-content: center; cursor: pointer;
            }
        </style>

    </head>
    <body class="bg-body-tertiary">
        <nav class="navbar navbar-expand-lg">
            <div class="container">
                <a class="navbar-brand icon-link" href="#">
                    <span class="material-symbols-rounded fs-2 pe-2 text-primary">thumbs_up_down</span>
                    <span class=" fs-1 fw-bold text-dark">ThumbRank</span>
                </a>

                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarContent" aria-controls="navbarContent" aria-expanded="false" aria-label="<?php echo gettext("Toggle navigation"); ?>">
                      <span class="navbar-toggler-icon"></span>
                </button>

                <div class="collapse navbar-collapse" id="navbarContent">
                    <div class="pt-3 pt-lg-0 ms-auto d-flex align-items-center">
                        <ul class="navbar-nav">
                            <li class="nav-item dropdown">
                                <a class="nav-link dropdown-toggle icon-link" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                    <span class="material-symbols-rounded align-middle fs-5">globe</span>
                                    <?php echo strtoupper($current_user_lang); ?>
                                </a>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item" href="?<?php echo http_build_query(array_merge($_GET, ["lang" => "de"])); ?>"><?php echo gettext("German"); ?></a></li>
                                    <li><a class="dropdown-item" href="?<?php echo http_build_query(array_merge($_GET, ["lang" => "en"])); ?>"><?php echo gettext("English"); ?></a></li>
                                </ul>
                            </li>
                        </ul>

                        <?php if ($room): ?>
                            <span onclick="copy_room_link()" class="badge bg-secondary ms-2"><?php echo gettext("Room"); ?>: <?php echo htmlspecialchars($room["room_code"]); ?></span>
                            <a href="?" class="btn btn-outline-secondary btn-sm ms-2"><?php echo gettext("Leave room"); ?></a>
                            <?php if ($is_room_owner): ?>
                                <form method="POST" onsubmit="return confirm('<?php echo gettext("Do you want to delete the room completely?"); ?>');" class=" ms-2">
                                    <button type="submit" name="delete_room" class="btn btn-outline-danger btn-sm"><?php echo gettext("Delete room"); ?></button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>

                    </div>

                </div>
            </div>
        </nav>

        <div class="container py-5">

            <?php if (!$room): ?>
                <div class="card p-5 text-center shadow mx-auto" style="max-width: 500px;">
                    <h3><?php echo gettext("Create new room"); ?></h3>
                    <p class="text-muted"><?php echo gettext("Start new session for your team."); ?></p>

                    <form method="POST">
                        <div class="mb-4">
                            <input type="text" name="room_name" class="form-control" placeholder="<?php echo gettext("Room name (eg. vlog january)"); ?>" required>
                        </div>

                        <button type="submit" name="create_room" class="btn btn-primary w-100 btn-lg"><?php echo gettext("Open room"); ?></button>
                    </form>
                </div>
            <?php endif; ?>

            <?php if ($room): ?>

                <div class="card p-4 mb-5 shadow-sm">
                    <h5 class="card-title pb-2"><?php echo gettext("Add YouTube video"); ?></h5>
                    <form method="POST" class="row g-2">
                        <div class="col-md-10">
                            <input type="text" name="yt_video_url" class="form-control" placeholder="https://www.youtube.com/watch?v=..." required>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" name="add_video" class="btn btn-primary w-100"><?php echo gettext("Add"); ?></button>
                        </div>
                    </form>
                </div>

                <h4 class="mb-3"><?php echo gettext("Rate thumbnails"); ?> (<?php echo count($videos); ?>)</h4>

                <?php if (empty($videos)): ?>
                    <div class="col-12 py-3">
                        <p class="text-muted">
                            <?php echo gettext("No videos in this room yet. Add the first link above!"); ?>
                        </p>
                    </div>
                <?php endif; ?>

                <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4">
                    <?php foreach ($videos as $vid): ?>
                        <?php
                            $thumbUrl = "https://img.youtube.com/vi/" . $vid["youtube_id"] . "/maxresdefault.jpg";
                            $can_delete = ($is_room_owner || $vid["submitted_by_session_id"] === $user_id);
                        ?>
                        <div class="col">
                            <div class="card h-100 shadow-sm">
                                <img class="card-img-top w-100" src="<?php echo $thumbUrl; ?>" alt="Thumbnail" loading="lazy">

                                <?php if ($can_delete): ?>
                                    <form method="POST" onsubmit="return confirm('<?php echo gettext("Delete thumbnail?"); ?>');">
                                        <input type="hidden" name="video_id" value="<?php echo $vid["id"]; ?>">
                                        <button type="submit" name="delete_video" class="btn btn-danger btn-delete-video" title="<?php echo gettext("Remove thumbnail"); ?>">
                                            <span class="material-symbols-rounded fs-6">close</span>
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <div class="card-body">
                                    <div class="d-flex justify-content-between mb-2 fw-bold">
                                        <span class="text-success">
                                            <?php echo $vid["likes"]; ?>
                                            <?php echo ngettext("Would click", "Would click", $vid["likes"]); ?>
                                        </span>
                                        <span class="text-danger">
                                            <?php echo $vid["dislikes"]; ?>
                                            <?php echo ngettext("Would skip", "Would skip", $vid["dislikes"]); ?>
                                        </span>
                                    </div>
                                    <div class="progress mb-3" style="height: 6px;">
                                        <?php
                                            $total = $vid["likes"] + $vid["dislikes"];
                                            $percent = $total > 0 ? ($vid["likes"] / $total) * 100 : 50;
                                        ?>
                                        <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo $percent; ?>%"></div>
                                        <div class="progress-bar bg-danger" role="progressbar" style="width: <?php echo (100-$percent); ?>%"></div>
                                    </div>

                                    <form method="POST" class="d-flex justify-content-between">
                                        <input type="hidden" name="video_id" value="<?php echo $vid["id"]; ?>">
                                        <input type="hidden" name="vote" value="1">

                                        <button type="submit" name="vote_value" value="1" style="width:48%;" class="btn icon-link <?php echo ($vid["current_user_vote"] === 1) ? "btn-success text-white" : "btn-outline-success"; ?>">
                                            <span class="material-symbols-rounded">thumb_up</span>
                                            <?php echo gettext("Would click"); ?>
                                        </button>

                                        <button type="submit" name="vote_value" value="0" style="width:48%;" class="btn icon-link <?php echo ($vid["current_user_vote"] === 0) ? "btn-danger text-white" : "btn-outline-danger"; ?>">
                                            <span class="material-symbols-rounded">thumb_down</span>
                                            <?php echo gettext("Rather not"); ?>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                </div>
            <?php endif; ?>

        </div>

        <div class="pt-2 pt-md-0 text-center pb-5">
            <?php echo gettext("Made with"); ?>
            <span class="material-symbols-rounded fs-6">favorite</span>
            <?php echo gettext("by"); ?>
           <a class="text-dark" href="https://simon-eller.at">Simon Eller</a>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
        <script>
          function copy_room_link() {
            navigator.clipboard.writeText(window.location.href);
          }
        </script>
    </body>
</html>
