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
        votes_visible INTEGER DEFAULT 0,
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

    $stmt = $pdo->prepare("INSERT INTO rooms (room_code, name, creator_session_id, votes_visible) VALUES (?, ?, ?, 0)");
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

        // If form to toggle voting results visibility was submitted
        if ($is_room_owner && isset($_POST["toggle_visibility"])) {
            $new_votes_visibility = $room["votes_visible"] ? 0 : 1;
            $stmt = $pdo->prepare("UPDATE rooms SET votes_visible = ? WHERE id = ?");
            $stmt->execute([$new_votes_visibility, $room["id"]]);
            header("Location: ?room=" . $room_code);
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

        <link href="css/material-symbols.css" rel="stylesheet">
        <link href="css/dm-sans.css" rel="stylesheet">
        <link href="css/bootstrap.min.css" rel="stylesheet">
        <link rel="icon" href="img/app-logo.svg">
    </head>

    <body>

        <a id="skip-link" class="visually-hidden-focusable skip-link" href="#main-content"><?php echo gettext("Jump to content"); ?></a>

        <button class="btn drawer-toggle drawer-toggle-open" id="drawer-open"
                type="button" data-drawer-toggle
                aria-controls="drawer-nav" aria-expanded="true"
                aria-label="<?php echo htmlspecialchars(gettext("Open menu"), ENT_QUOTES); ?>"
                title="<?php echo htmlspecialchars(gettext("Open menu"), ENT_QUOTES); ?>">
            <span class="material-symbols-outlined" aria-hidden="true">menu</span>
        </button>

        <div class="drawer-backdrop" id="drawer-backdrop" aria-hidden="true" hidden></div>

        <!-- Drawer -->
        <aside class="drawer drawer-left show" id="drawer-nav">
            <header class="drawer-header py-1 px-2">
                <div class="drawer-header-content">
                    <img src="img/app-logo.svg" alt="" class="drawer-logo">
                    <span class="drawer-brand">ThumbRank</span>
                </div>

                <button id="drawer-close" class="btn drawer-toggle ms-auto" type="button"
                        data-drawer-toggle data-drawer-dismiss
                        aria-controls="drawer-nav" aria-expanded="true"
                        aria-label="<?php echo htmlspecialchars(gettext("Close menu"), ENT_QUOTES); ?>"
                        title="<?php echo htmlspecialchars(gettext("Close menu"), ENT_QUOTES); ?>">
                    <span class="material-symbols-outlined" aria-hidden="true">close</span>
                </button>
            </header>

            <div class="drawer-content">
                <p class="drawer-nav-item drawer-nav-item-static mb-0" role="status">
                    <span class="drawer-nav-icon" aria-hidden="true">
                        <span class="material-symbols-outlined">meeting_room</span>
                    </span>
                    <span class="drawer-nav-label text-truncate">
                        <?php echo $room ? htmlspecialchars($room["name"]) : gettext("No room open"); ?>
                    </span>
                </p>

                <nav class="drawer-nav" aria-label="<?php echo htmlspecialchars(gettext("Main navigation"), ENT_QUOTES); ?>">
                    <ul class="list-unstyled mb-0">

                        <!-- Room -->
                        <li class="drawer-nav-group">
                            <h2 class="drawer-nav-heading">
                                <button class="drawer-nav-item drawer-nav-toggle" type="button"
                                        data-bs-toggle="collapse" data-bs-target="#nav-room"
                                        aria-expanded="true" aria-controls="nav-room">
                                    <span class="drawer-nav-chevron" aria-hidden="true">
                                        <span class="collapsed-icon material-symbols-outlined">chevron_right</span>
                                        <span class="expanded-icon material-symbols-outlined">expand_more</span>
                                    </span>
                                    <span class="drawer-nav-label text-truncate"><?php echo gettext("Room"); ?></span>
                                </button>
                            </h2>

                            <div class="collapse show" id="nav-room">
                                <ul class="list-unstyled mb-0">
                                    <?php if ($room): ?>
                                        <li>
                                            <button id="copy-room-link" class="drawer-nav-item drawer-nav-sub" type="button"
                                                    data-copied-text="<?php echo htmlspecialchars(gettext("Link copied"), ENT_QUOTES); ?>"
                                                    title="<?php echo htmlspecialchars(gettext("Copy room link"), ENT_QUOTES); ?>">
                                                <span class="drawer-nav-icon" aria-hidden="true">
                                                    <span class="material-symbols-outlined">content_copy</span>
                                                </span>
                                                <span class="drawer-nav-label text-truncate" data-copy-label><?php echo gettext("Room"); ?>: <?php echo htmlspecialchars($room["room_code"]); ?></span>
                                            </button>
                                        </li>

                                        <?php if ($is_room_owner): ?>
                                            <li>
                                                <form method="POST" class="m-0">
                                                    <button type="submit" name="toggle_visibility" class="drawer-nav-item drawer-nav-sub">
                                                        <span class="drawer-nav-icon" aria-hidden="true">
                                                            <span class="material-symbols-outlined"><?php echo $room["votes_visible"] ? "visibility_off" : "visibility"; ?></span>
                                                        </span>
                                                        <span class="drawer-nav-label text-truncate"><?php echo $room["votes_visible"] ? gettext("Hide results") : gettext("Show results"); ?></span>
                                                    </button>
                                                </form>
                                            </li>
                                            <li>
                                                <form method="POST" class="m-0" onsubmit="return confirm(<?php echo htmlspecialchars(json_encode(gettext("Do you want to delete the room completely?")), ENT_QUOTES); ?>);">
                                                    <button type="submit" name="delete_room" class="drawer-nav-item drawer-nav-sub text-danger">
                                                        <span class="drawer-nav-icon text-danger" aria-hidden="true">
                                                            <span class="material-symbols-outlined">delete</span>
                                                        </span>
                                                        <span class="drawer-nav-label text-truncate"><?php echo gettext("Delete room"); ?></span>
                                                    </button>
                                                </form>
                                            </li>
                                        <?php endif; ?>

                                        <li>
                                            <a class="drawer-nav-item drawer-nav-sub" href="?">
                                                <span class="drawer-nav-icon" aria-hidden="true">
                                                    <span class="material-symbols-outlined">logout</span>
                                                </span>
                                                <span class="drawer-nav-label text-truncate"><?php echo gettext("Leave room"); ?></span>
                                            </a>
                                        </li>
                                    <?php else: ?>
                                        <li>
                                            <a class="drawer-nav-item drawer-nav-sub" href="?" aria-current="page">
                                                <span class="drawer-nav-icon" aria-hidden="true">
                                                    <span class="material-symbols-outlined">add</span>
                                                </span>
                                                <span class="drawer-nav-label text-truncate"><?php echo gettext("Create new room"); ?></span>
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </li>

                        <!-- Settings -->
                        <li class="drawer-nav-group">
                            <h2 class="drawer-nav-heading">
                                <button class="drawer-nav-item drawer-nav-toggle" type="button"
                                        data-bs-toggle="collapse" data-bs-target="#nav-settings"
                                        aria-expanded="true" aria-controls="nav-settings">
                                    <span class="drawer-nav-chevron" aria-hidden="true">
                                        <span class="collapsed-icon material-symbols-outlined">chevron_right</span>
                                        <span class="expanded-icon material-symbols-outlined">expand_more</span>
                                    </span>
                                    <span class="drawer-nav-label text-truncate"><?php echo gettext("Settings"); ?></span>
                                </button>
                            </h2>

                            <div class="collapse show" id="nav-settings">
                                <ul class="list-unstyled mb-0">
                                    <li class="dropdown">
                                        <button class="drawer-nav-item drawer-nav-sub dropdown-toggle" type="button"
                                                data-bs-toggle="dropdown" data-bs-display="static"
                                                aria-expanded="false">
                                            <span class="drawer-nav-icon" aria-hidden="true">
                                                <span class="material-symbols-outlined">language</span>
                                            </span>
                                            <span class="drawer-nav-label text-truncate">
                                                <?php echo gettext("Language"); ?>: <?php echo strtoupper($current_user_lang); ?>
                                            </span>
                                        </button>
                                        <ul class="dropdown-menu" aria-label="<?php echo htmlspecialchars(gettext("Choose language"), ENT_QUOTES); ?>">
                                            <li>
                                                <a class="dropdown-item" href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ["lang" => "de"])), ENT_QUOTES); ?>"
                                                   <?php echo $current_user_lang === "de" ? 'aria-current="true"' : ""; ?>><?php echo gettext("German"); ?></a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ["lang" => "en"])), ENT_QUOTES); ?>"
                                                   <?php echo $current_user_lang === "en" ? 'aria-current="true"' : ""; ?>><?php echo gettext("English"); ?></a>
                                            </li>
                                        </ul>
                                    </li>
                                </ul>
                            </div>
                        </li>

                    </ul>
                </nav>
            </div>

            <div class="drawer-footer text-center">
                <a href="https://simon-eller.at" target="_blank" rel="noopener noreferrer">
                    <img src="img/simon-eller-logo.svg" alt="Simon Eller" style="height: 2rem;">
                </a>
            </div>
        </aside>

        <!-- Main -->
        <main id="main-content" tabindex="-1" class="min-vh-100 d-flex flex-column p-4 pt-5">

            <?php if (!$room): ?>

                <!-- Create room -->
                <div class="flex-grow-1 d-flex flex-column align-items-center justify-content-center text-center">
                    <span class="material-symbols-outlined display-1 fw-normal text-primary opacity-50" aria-hidden="true">meeting_room</span>
                    <h1 class="fw-bold fs-2 mb-1"><?php echo gettext("Create new room"); ?></h1>
                    <p class="text-body-secondary mb-4"><?php echo gettext("Start new session for your team."); ?></p>

                    <div class="row justify-content-center w-100">
                        <div class="col-12 col-md-8 col-lg-6 col-xl-4">
                            <div class="card border rounded-4">
                                <div class="card-body">
                                    <form method="POST">
                                        <div class="mb-3 text-start">
                                            <label for="room-name" class="form-label"><?php echo gettext("Room name"); ?></label>
                                            <input type="text" id="room-name" name="room_name" class="form-control"
                                                   placeholder="<?php echo htmlspecialchars(gettext("Room name (eg. vlog january)"), ENT_QUOTES); ?>" required>
                                        </div>

                                        <button type="submit" name="create_room" class="btn btn-primary btn-lg fw-semibold icon-link justify-content-center w-100">
                                            <span class="material-symbols-outlined" aria-hidden="true">add</span>
                                            <span><?php echo gettext("Open room"); ?></span>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            <?php else: ?>

                <!-- Room -->
                <h1 class="fw-bold fs-2 mb-1"><?php echo htmlspecialchars($room["name"]); ?></h1>
                <p class="text-body-secondary"><?php echo gettext("Paste a YouTube link to collect thumbnails and vote on them."); ?></p>

                <div class="card border rounded-4 mb-4">
                    <div class="card-body">
                        <h2 class="fw-semibold fs-6 mb-3 icon-link">
                            <span class="material-symbols-outlined text-primary" aria-hidden="true">add_link</span>
                            <span><?php echo gettext("Add YouTube video"); ?></span>
                        </h2>

                        <form method="POST" class="d-flex gap-2 flex-wrap">
                            <label for="yt-video-url" class="visually-hidden"><?php echo gettext("Add YouTube video"); ?></label>
                            <input type="url" id="yt-video-url" name="yt_video_url" class="form-control flex-grow-1 w-auto"
                                   placeholder="https://www.youtube.com/watch?v=..." required>
                            <button type="submit" name="add_video" class="btn btn-primary fw-semibold icon-link flex-shrink-0">
                                <span class="material-symbols-outlined" aria-hidden="true">add</span>
                                <span><?php echo gettext("Add"); ?></span>
                            </button>
                        </form>
                    </div>
                </div>

                <h2 class="fw-semibold fs-6 mb-3 icon-link">
                    <span class="material-symbols-outlined text-primary" aria-hidden="true">leaderboard</span>
                    <span><?php echo gettext("Rate thumbnails"); ?> (<?php echo count($videos); ?>)</span>
                </h2>

                <?php if (empty($videos)): ?>

                    <div class="flex-grow-1 d-flex flex-column align-items-center justify-content-center text-center py-5">
                        <span class="material-symbols-outlined display-1 fw-normal text-primary opacity-50" aria-hidden="true">add_photo_alternate</span>
                        <h3 class="fw-bold fs-4 mb-1"><?php echo gettext("No thumbnails yet"); ?></h3>
                        <p class="text-body-secondary mb-0"><?php echo gettext("No videos in this room yet. Add the first link above!"); ?></p>
                    </div>

                <?php else: ?>

                    <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-4">
                        <?php foreach ($videos as $vid): ?>
                            <?php
                                $thumbUrl = "https://img.youtube.com/vi/" . $vid["youtube_id"] . "/maxresdefault.jpg";
                                $can_delete = ($is_room_owner || $vid["submitted_by_session_id"] === $user_id);

                                $show_stats = $room["votes_visible"] || $is_room_owner;

                                $total = $vid["likes"] + $vid["dislikes"];
                                $percent = $total > 0 ? ($vid["likes"] / $total) * 100 : 50;
                            ?>
                            <div class="col">
                                <div class="card border rounded-4 h-100 position-relative overflow-hidden">
                                    <img class="card-img-top w-100" src="<?php echo $thumbUrl; ?>" alt="" loading="lazy">

                                    <?php if ($can_delete): ?>
                                        <form method="POST" class="position-absolute top-0 end-0 m-2"
                                              onsubmit="return confirm(<?php echo htmlspecialchars(json_encode(gettext("Delete thumbnail?")), ENT_QUOTES); ?>);">
                                            <input type="hidden" name="video_id" value="<?php echo $vid["id"]; ?>">
                                            <button type="submit" name="delete_video" class="btn btn-danger btn-sm rounded-circle lh-1 p-1 d-flex"
                                                    aria-label="<?php echo htmlspecialchars(gettext("Remove thumbnail"), ENT_QUOTES); ?>"
                                                    title="<?php echo htmlspecialchars(gettext("Remove thumbnail"), ENT_QUOTES); ?>">
                                                <span class="material-symbols-outlined fs-6" aria-hidden="true">close</span>
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <div class="card-body d-flex flex-column">
                                        <?php if ($show_stats): ?>

                                            <div class="<?php echo (!$room["votes_visible"] && $is_room_owner) ? "opacity-50" : ""; ?>">
                                                <div class="d-flex justify-content-between align-items-center gap-2 mb-2 small fw-semibold">
                                                    <span class="text-success icon-link">
                                                        <span class="material-symbols-outlined fs-6" aria-hidden="true">thumb_up</span>
                                                        <span><?php echo $vid["likes"]; ?> <?php echo ngettext("Would click", "Would click", $vid["likes"]); ?></span>
                                                    </span>
                                                    <span class="text-danger icon-link text-end">
                                                        <span><?php echo $vid["dislikes"]; ?> <?php echo ngettext("Would skip", "Would skip", $vid["dislikes"]); ?></span>
                                                        <span class="material-symbols-outlined fs-6" aria-hidden="true">thumb_down</span>
                                                    </span>
                                                </div>

                                                <div class="progress-stacked mb-3">
                                                    <div class="progress" role="progressbar"
                                                         aria-label="<?php echo htmlspecialchars(gettext("Would click"), ENT_QUOTES); ?>"
                                                         aria-valuenow="<?php echo round($percent); ?>" aria-valuemin="0" aria-valuemax="100"
                                                         style="width: <?php echo $percent; ?>%">
                                                        <div class="progress-bar bg-success"></div>
                                                    </div>
                                                    <div class="progress" role="progressbar"
                                                         aria-label="<?php echo htmlspecialchars(gettext("Would skip"), ENT_QUOTES); ?>"
                                                         aria-valuenow="<?php echo round(100 - $percent); ?>" aria-valuemin="0" aria-valuemax="100"
                                                         style="width: <?php echo (100 - $percent); ?>%">
                                                        <div class="progress-bar bg-danger"></div>
                                                    </div>
                                                </div>

                                                <?php if (!$room["votes_visible"] && $is_room_owner): ?>
                                                    <p class="d-flex justify-content-center align-items-center gap-1 small fst-italic text-body-secondary mb-3">
                                                        <span class="material-symbols-outlined fs-6" aria-hidden="true">visibility_off</span>
                                                        <span><?php echo gettext("Visible only to you"); ?></span>
                                                    </p>
                                                <?php endif; ?>
                                            </div>

                                        <?php else: ?>

                                            <p class="d-flex flex-column align-items-center gap-1 border rounded-3 text-body-secondary small text-center py-2 mb-3">
                                                <span class="material-symbols-outlined" aria-hidden="true">visibility_off</span>
                                                <span><?php echo gettext("Votes hidden"); ?></span>
                                            </p>

                                        <?php endif; ?>

                                        <form method="POST" class="d-flex gap-2 mt-auto">
                                            <input type="hidden" name="video_id" value="<?php echo $vid["id"]; ?>">
                                            <input type="hidden" name="vote" value="1">

                                            <button type="submit" name="vote_value" value="1"
                                                    class="btn btn-sm px-2 flex-fill icon-link justify-content-center <?php echo ($vid["current_user_vote"] === 1) ? "btn-success" : "btn-outline-success"; ?>">
                                                <span class="material-symbols-outlined fs-6" aria-hidden="true">thumb_up</span>
                                                <span><?php echo gettext("Would click"); ?></span>
                                            </button>

                                            <button type="submit" name="vote_value" value="0"
                                                    class="btn btn-sm px-2 flex-fill icon-link justify-content-center <?php echo ($vid["current_user_vote"] === 0) ? "btn-danger" : "btn-outline-danger"; ?>">
                                                <span class="material-symbols-outlined fs-6" aria-hidden="true">thumb_down</span>
                                                <span><?php echo gettext("Rather not"); ?></span>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                <?php endif; ?>

            <?php endif; ?>

        </main>

        <script src="js/bootstrap.bundle.min.js"></script>
        <script src="js/drawer.js"></script>
        <script src="js/app.js"></script>
    </body>
</html>
