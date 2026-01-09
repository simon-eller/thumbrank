# 👍👎 ThumbRank

**ThumbRank** is a lightweight, session-based voting tool for YouTube thumbnails. It allows content creators and teams to create temporary "rooms" to vote on video thumbnails (click vs. skip) without requiring user registration.

## ✨ Features

* **Instant room creation:** Create a new voting session with a single click.
* **YouTube Integration:** Simply paste a YouTube URL to automatically fetch and display the high-resolution thumbnail.
* **Voting System:** Simple "Would click" (like) vs. "Rather not" (dislike) voting mechanism.
* **Live Ranking:** Videos are automatically sorted by popularity.
* **Session Management:** No login required. User identity is handled via PHP Sessions.
* **Permission System:**
* Room creators can delete the entire room.
* Users can delete thumbnails they submitted.


* **Internationalization (i18n):** Full support for English and German via `gettext`.
* **Responsive Design:** Built with Bootstrap 5, works on desktop and mobile.

## 🛠️ Technology Stack

* **Backend:** PHP (Native, no framework required)
* **Database:** SQLite (Zero-configuration, file-based)
* **Frontend:** HTML5, CSS3, Bootstrap 5.3
* **Icons:** Google Material Symbols
* **Localization:** GNU gettext

## 🚀 Installation & Setup

### Prerequisites

* Webserver (Apache or Nginx)
* PHP 7.4 or higher
* **Extensions required:**
* `pdo_sqlite` (for the database)
* `gettext` (for translations)


### Steps

1. **Clone the repository** (or copy files to your webroot):
```bash
git clone https://github.com/simon-eller/thumbrank.git
cd thumbrank
```

2. **Change default configuration:**
If you'd like to you can change the default language settings.
```php
$default_lang = "en";
```
