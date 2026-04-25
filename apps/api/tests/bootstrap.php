<?php

// Set APP_BASE_PATH so that Laravel's Application::inferBasePath() resolves to
// this worktree rather than the main tree. This is necessary when vendor/ is a
// symlink to a shared installation (e.g. in git worktrees), because inferBasePath()
// derives the path from the Application class file inside vendor, which would
// otherwise point to the main tree and cause the wrong bootstrap/app.php to load.
$_SERVER['APP_BASE_PATH'] = dirname(__DIR__);

require __DIR__.'/../vendor/autoload.php';
