<?php

declare(strict_types=1);

/**
 * Each user's colour mode: 'auto' follows the device (prefers-color-scheme), 'light' or 'dark' is fixed.
 * Stored on the account so it follows the user from computer to computer.
 */
return [
    "ALTER TABLE users ADD COLUMN theme VARCHAR(5) NOT NULL DEFAULT 'auto' AFTER locale",
];
