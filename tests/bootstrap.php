<?php

/**
 * Bootstrap for the unit suite.
 *
 * These run against plain PHP — no Craft application, no database. Everything that needs a
 * live Craft is exercised against the plugin-testing harness instead, as described in
 * tests/README.md.
 */

require dirname(__DIR__) . '/vendor/autoload.php';
