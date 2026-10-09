<?php

namespace justinholtweb\cleanair\events;

use craft\elements\User;
use craft\events\CancelableEvent;
use justinholtweb\cleanair\models\Report;
use justinholtweb\cleanair\models\SavedFilter;

/**
 * Fired just before a scheduled report is sent — scheduled or test.
 *
 * Change who gets it, retitle it, add to what the templates see, or set `isValid` to false to
 * stop it. Nothing has been exported yet when this fires, so a cancelled report leaves no file
 * behind, and a cancelled scheduled report leaves its marker where it was, so the next run asks
 * again.
 */
class ReportEvent extends CancelableEvent
{
    public ?Report $report = null;

    public ?SavedFilter $savedFilter = null;

    /** @var User[] Who it goes to. Each one is checked again after the event. */
    public array $recipients = [];

    public string $subject = '';

    /** @var array<string, mixed> What `_emails/report.twig` and `_emails/report.txt.twig` receive. */
    public array $variables = [];

    /** True for "Send me a test now". */
    public bool $isTest = false;
}
