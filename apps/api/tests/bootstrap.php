<?php

// Set APP_BASE_PATH so that Laravel's Application::inferBasePath() resolves to
// this worktree rather than the main tree. This is necessary when vendor/ is a
// symlink to a shared installation (e.g. in git worktrees), because inferBasePath()
// derives the path from the Application class file inside vendor, which would
// otherwise point to the main tree and cause the wrong bootstrap/app.php to load.
$_SERVER['APP_BASE_PATH'] = dirname(__DIR__);

// Remove the PHP CLI default 300-second cap for the entire phpunit run.
// The phpunit.xml `<ini name="max_execution_time" value="0">` directive only
// takes effect after phpunit parses its config, but the test suite runs long
// enough that the wall-clock 300s SIGALRM still fires mid-suite if a
// controller-under-test (e.g. ImportController::set_time_limit(300)) caps
// the budget downward. Setting it here ensures both the pre-config and
// post-config phases have a 0-second budget. M1.6 of the dev remediation
// plan documents this gate.
set_time_limit(0);
ini_set('max_execution_time', '0');

require __DIR__.'/../vendor/autoload.php';
