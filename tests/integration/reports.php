<?php
/**
 * Scheduled reports: schedule, the per-report marker, who a report runs as and goes to, the
 * email, the queue fallback and the console commands.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-cleanair/tests/integration/reports.php
 *
 * Self-cleaning: its saved filters (their schedules go with them), the exports they wrote, and
 * its users are removed at the end. Mail goes to a null transport and is captured from the
 * mailer's own event; pushes to the queue are captured and skipped. Every run is driven with an
 * explicit `$now`, never the clock. Nothing here triggers garbage collection.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\Db;
use craft\mail\Message;
use justinholtweb\cleanair\events\ReportEvent;
use justinholtweb\cleanair\models\FilterSet;
use justinholtweb\cleanair\models\Report;
use justinholtweb\cleanair\models\SavedFilter;
use justinholtweb\cleanair\Plugin;
use justinholtweb\cleanair\queue\SendReport;
use justinholtweb\cleanair\records\ExportRecord;
use justinholtweb\cleanair\services\Reports;
use Symfony\Component\Mailer\Transport\NullTransport;
use yii\base\Event;
use yii\mail\BaseMailer;
use yii\mail\MailEvent;
use yii\queue\PushEvent;
use yii\queue\Queue;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";
            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

Craft::$app->getPlugins()->loadPlugins();

$plugin = Plugin::getInstance();
$reports = $plugin->reports;
$originalEdition = $plugin->edition;
$plugin->edition = Plugin::EDITION_PRO;
$originalSettings = $plugin->getSettings()->toArray();
$tz = new DateTimeZone(Craft::$app->getTimeZone());
$run = bin2hex(random_bytes(3));

/** A moment in the system time zone. */
function at(string $when): DateTimeImmutable
{
    global $tz;

    return new DateTimeImmutable($when, $tz);
}

// ---------------------------------------------------------------------------------- capture

Craft::$app->getMailer()->setTransport(new NullTransport());

/** @var Message[] $mail */
$mail = [];
$failMail = false;

Event::on(BaseMailer::class, BaseMailer::EVENT_BEFORE_SEND, function(MailEvent $event) use (&$mail, &$failMail) {
    if ($failMail) {
        $event->isValid = false;
        return;
    }
    $mail[] = $event->message;
});

$pushed = [];

Event::on(Queue::class, Queue::EVENT_BEFORE_PUSH, function(PushEvent $event) use (&$pushed) {
    if ($event->job instanceof SendReport) {
        $pushed[] = $event->job;
        // Captured, not queued: the harness queue would otherwise send it for real.
        $event->handled = true;
    }
});

/** Who each captured message went to. */
function recipientsOf(array $messages): array
{
    $to = array_map(fn(Message $m) => array_key_first((array)$m->getTo()), $messages);
    sort($to);
    return $to;
}

function htmlOf(Message $message): string
{
    return (string)$message->getSymfonyEmail()->getHtmlBody();
}

function attachmentsOf(Message $message): array
{
    return array_map(fn($part) => $part->getFilename(), $message->getSymfonyEmail()->getAttachments());
}

// ---------------------------------------------------------------------------------- fixtures

$users = [];
$savedFilters = [];

register_shutdown_function(function() use (&$users, &$savedFilters, $originalEdition, $originalSettings) {
    $plugin = Plugin::getInstance();
    $filterIds = array_map(fn(SavedFilter $f) => $f->id, $savedFilters);
    $userIds = array_map(fn(User $u) => $u->id, $users);

    // Through the service, which deletes the file as well as the row: a report of users is
    // personal data, and must not be left in storage.
    $records = ExportRecord::find()->where(['or', ['filterId' => $filterIds ?: [0]], ['userId' => $userIds ?: [0]]])->all();
    foreach ($records as $record) {
        $plugin->exports->delete($record);
    }

    foreach ($filterIds as $id) {
        $plugin->savedFilters->delete((int)$id);
    }

    foreach ($users as $user) {
        Craft::$app->getElements()->deleteElement($user, true);
    }

    $plugin->edition = $originalEdition;
    $plugin->setSettings($originalSettings);
});

function makeUser(string $name, bool $admin, bool $suspended = false): User
{
    global $run, $users;

    $user = new User();
    $user->username = "cleanair-report-$name-$run";
    $user->email = "cleanair-report-$name-$run@example.com";
    $user->admin = $admin;
    Craft::$app->getElements()->saveElement($user, false);
    Craft::$app->getUsers()->activateUser($user);

    if ($suspended) {
        Craft::$app->getUsers()->suspendUser($user);
    }

    $users[] = $user;

    return User::find()->id($user->id)->status(null)->one();
}

$owner = makeUser('owner', true);
$colleague = makeUser('colleague', true);
$nobody = makeUser('nobody', false);
$suspended = makeUser('suspended', true, true);

// Six consecutive entries: an ID range over them matches exactly the ones in it.
$entryIds = array_map('intval', Entry::find()->status(null)->orderBy(['elements.id' => SORT_ASC])->limit(6)->ids());

if (count($entryIds) < 6) {
    echo "This check needs at least six entries in the install.\n";
    exit(1);
}

function entryRange(int $from, int $to): FilterSet
{
    global $entryIds;

    return FilterSet::fromArray([
        'elementType' => Entry::class,
        'source' => null,
        'criteria' => [['field' => 'id', 'operator' => 'between', 'value' => $entryIds[$from], 'value2' => $entryIds[$to]]],
    ]);
}

function makeSaved(string $name, FilterSet $filter, User $owner): SavedFilter
{
    global $run, $savedFilters;

    $saved = new SavedFilter([
        'name' => "Report check $name $run",
        'elementType' => $filter->elementType,
        'source' => $filter->source,
        'filter' => $filter,
        'userId' => $owner->id,
    ]);
    $errors = [];
    Plugin::getInstance()->savedFilters->save($saved, $errors) || throw new RuntimeException(implode(' ', $errors));
    $savedFilters[] = $saved;

    return $saved;
}

/** A schedule with its marker cleared, so runs are driven by the `$now` each check passes. */
function makeReport(SavedFilter $saved, User $owner, array $config = []): Report
{
    $report = new Report(array_merge([
        'filterId' => $saved->id,
        'ownerId' => $owner->id,
        'frequency' => Report::FREQUENCY_WEEKLY,
        'weekday' => 1,
        'hour' => 8,
        'recipientIds' => [$owner->id],
        'format' => 'csv',
        'sendWhen' => Report::SEND_RESULTS,
        'delivery' => Report::DELIVERY_ATTACH,
    ], $config));

    Plugin::getInstance()->reports->save($report) || throw new RuntimeException(json_encode($report->getErrors()));
    Db::update(Reports::TABLE, ['period' => null], ['id' => $report->id]);

    return Plugin::getInstance()->reports->getById($report->id);
}

function fresh(Report $report): Report
{
    return Plugin::getInstance()->reports->getById((int)$report->id);
}

$saved = makeSaved('entries', entryRange(0, 2), $owner);
$report = makeReport($saved, $owner, ['recipientIds' => [$owner->id, $colleague->id]]);

echo "Clean Air scheduled reports\n";
echo str_repeat('=', 60) . "\n";

// ---------------------------------------------------------------------------------- schedule
section('Schedule');

check('daily, weekly and monthly period keys', function() use ($reports) {
    $now = at('2026-10-07 09:00');
    $keys = [
        $reports->periodKey(new Report(['frequency' => 'daily']), $now),
        $reports->periodKey(new Report(['frequency' => 'weekly']), $now),
        $reports->periodKey(new Report(['frequency' => 'monthly']), $now),
    ];

    return $keys === ['2026-10-07', '2026-W41', '2026-10'] ?: json_encode($keys);
});

check('the ISO week year, not the calendar year: 2027-01-01 is 2026-W53', fn() =>
    $reports->periodKey(new Report(['frequency' => 'weekly']), at('2027-01-01 12:00')) === '2026-W53');

check('weekly is due from the chosen weekday and hour of the current week', fn() =>
    $reports->dueAt(new Report(['frequency' => 'weekly', 'weekday' => 1, 'hour' => 8]), at('2026-10-07 09:00'))->format('Y-m-d H:i') === '2026-10-05 08:00');

check('monthly is due from the chosen day of the current month', fn() =>
    $reports->dueAt(new Report(['frequency' => 'monthly', 'monthDay' => 15, 'hour' => 6]), at('2026-10-20 09:00'))->format('Y-m-d H:i') === '2026-10-15 06:00');

check('not due the minute before the hour', fn() => !$reports->isDue($report, at('2026-10-05 07:59')));
check('due on the hour', fn() => $reports->isDue($report, at('2026-10-05 08:00')));
check('a missed hour still sends later in the same period', fn() => $reports->isDue($report, at('2026-10-07 15:00')));
check('a switched-off report is never due', fn() => !$reports->isDue(new Report(['enabled' => false, 'frequency' => 'daily', 'hour' => 0]), at('2026-10-07 15:00')));

check('a schedule saved after this period’s hour starts next period, not now', fn() =>
    $reports->initialPeriod(new Report(['frequency' => 'weekly', 'weekday' => 1, 'hour' => 8]), at('2026-10-07 09:00')) === '2026-W41');

check('a schedule saved before this period’s hour still sends this period', fn() =>
    $reports->initialPeriod(new Report(['frequency' => 'weekly', 'weekday' => 1, 'hour' => 8]), at('2026-10-05 07:00')) === null);

check('the next due date of a report that went this week is next week’s', function() use ($reports) {
    $r = new Report(['frequency' => 'weekly', 'weekday' => 1, 'hour' => 8, 'period' => '2026-W41']);

    return $reports->nextDueAt($r, at('2026-10-07 09:00'))->format('Y-m-d H:i') === '2026-10-12 08:00';
});

check('monthly next due skips to next month without overflowing', function() use ($reports) {
    $r = new Report(['frequency' => 'monthly', 'monthDay' => 28, 'hour' => 8, 'period' => '2027-01']);

    return $reports->nextDueAt($r, at('2027-01-31 09:00'))->format('Y-m-d') === '2027-02-28';
});

check('the model refuses a 29th, an hour of 24 and no recipients', function() {
    $r = new Report(['monthDay' => 29, 'hour' => 24, 'recipientIds' => []]);
    $r->validate();

    return $r->hasErrors('monthDay') && $r->hasErrors('hour') && $r->hasErrors('recipientIds') ?: json_encode($r->getErrors());
});

check('posted strings are cleaned: recipient IDs, numbers, the lightswitch', function() {
    $r = new Report();
    $r->setAttributes(['recipientIds' => ['3', '', 'x', '3', '7'], 'hour' => '6', 'enabled' => '']);

    return $r->recipientIds === [3, 7] && $r->hour === 6 && $r->enabled === false ?: json_encode($r->toArray());
});

// ------------------------------------------------------------------------------------ running
section('Running');

check('not due: nothing sent, marker untouched', function() use ($reports, $report, &$mail) {
    $mail = [];
    $result = $reports->run(fresh($report), at('2026-10-05 07:00'));

    return $result === Reports::RESULT_NOT_DUE && $mail === [] && fresh($report)->period === null ?: $result;
});

check('due: one message per recipient, each addressed alone', function() use ($reports, $report, &$mail, $owner, $colleague) {
    $mail = [];
    $result = $reports->run(fresh($report), at('2026-10-05 08:00'));
    $to = recipientsOf($mail);
    $expected = [$owner->email, $colleague->email];
    sort($expected);

    return $result === Reports::RESULT_SENT
        && $to === $expected
        && count((array)$mail[0]->getTo()) === 1
        ?: "$result, to " . json_encode($to);
});

check('the subject names the filter and the count', fn() =>
    str_contains((string)$mail[0]->getSubject(), $saved->name) && str_contains((string)$mail[0]->getSubject(), '3 results') ?: (string)$mail[0]->getSubject());

check('entries are attached as the chosen format', fn() =>
    count(attachmentsOf($mail[0])) === 1 && str_ends_with(attachmentsOf($mail[0])[0], '.csv') ?: json_encode(attachmentsOf($mail[0])));

check('the email lists the first rows, linked to the control panel', function() use ($mail, $entryIds) {
    $html = htmlOf($mail[0]);
    $label = (string)Entry::find()->id($entryIds[0])->status(null)->one()->getUiLabel();

    return str_contains($html, htmlspecialchars($label, ENT_QUOTES)) && str_contains($html, 'The first report') ?: 'missing first entry or first-report line';
});

check('the text half renders too', fn() => str_contains((string)$mail[0]->getSymfonyEmail()->getTextBody(), $saved->name));

check('the marker records the period, the count and what was in it', function() use ($report, $entryIds, $reports) {
    $r = fresh($report);
    $seen = $reports->seen($r);

    return $r->period === '2026-W41' && $r->lastResult === 'sent' && $r->lastCount === 3 && $r->lastNewCount === 3
        && $seen === array_slice($entryIds, 0, 3)
        ?: json_encode([$r->period, $r->lastResult, $r->lastCount, $r->lastNewCount, $seen]);
});

check('the export is the owner’s, linked to the saved filter', function() use ($saved, $owner) {
    $record = ExportRecord::find()->where(['filterId' => $saved->id])->orderBy(['id' => SORT_DESC])->one();

    return $record && (int)$record->userId === (int)$owner->id && $record->status === 'done' && (int)$record->rowCount === 3 ?: 'no owner export';
});

check('running again in the same period sends nothing', function() use ($reports, $report, &$mail) {
    $mail = [];
    $result = $reports->run(fresh($report), at('2026-10-06 10:00'));

    return $result === Reports::RESULT_ALREADY_SENT && $mail === [] ?: $result;
});

check('of two runs racing for a period, exactly one sends', function() use ($reports, $report, &$mail) {
    $mail = [];
    // Both loaded before either runs, so both believe the period is free.
    $a = fresh($report);
    $b = fresh($report);
    $first = $reports->run($a, at('2026-10-12 08:30'));
    $second = $reports->run($b, at('2026-10-12 08:30'));

    return $first === Reports::RESULT_SENT && $second === Reports::RESULT_ALREADY_SENT && count($mail) === 2 ?: "$first / $second, " . count($mail) . ' messages';
});

check('only-new: an unchanged result set sends nothing but takes the period', function() use ($reports, $report, &$mail) {
    $mail = [];
    $r = fresh($report);
    $r->sendWhen = Report::SEND_NEW;
    $reports->save($r);
    Db::update(Reports::TABLE, ['period' => '2026-W42'], ['id' => $r->id]);
    $result = $reports->run(fresh($report), at('2026-10-19 08:00'));

    return $result === Reports::RESULT_NOTHING_NEW && $mail === [] && fresh($report)->period === '2026-W43' ?: $result;
});

check('only-new: two more elements send, counted and listed as new', function() use ($reports, $report, $saved, &$mail, $entryIds) {
    $mail = [];
    $saved->filter = entryRange(0, 4);
    $errors = [];
    Plugin::getInstance()->savedFilters->save($saved, $errors);

    $result = $reports->run(fresh($report), at('2026-10-26 08:00'));
    $r = fresh($report);
    $html = $mail ? htmlOf($mail[0]) : '';
    $newLabel = (string)Entry::find()->id($entryIds[3])->status(null)->one()->getUiLabel();
    $oldLabel = (string)Entry::find()->id($entryIds[0])->status(null)->one()->getUiLabel();

    return $result === Reports::RESULT_SENT && $r->lastCount === 5 && $r->lastNewCount === 2
        && str_contains($html, 'New since last time') && str_contains($html, htmlspecialchars($newLabel, ENT_QUOTES))
        && ($newLabel === $oldLabel || !str_contains($html, '>' . htmlspecialchars($oldLabel, ENT_QUOTES) . '<'))
        ?: json_encode([$result, $r->lastCount, $r->lastNewCount]);
});

check('a failed send gives the period back, and the next run retries', function() use ($reports, $report, &$mail, &$failMail) {
    $mail = [];
    $r = fresh($report);
    $r->sendWhen = Report::SEND_ALWAYS;
    $reports->save($r);
    $before = fresh($report)->period;
    $failMail = true;
    $first = $reports->run(fresh($report), at('2026-11-02 08:00'));
    $failMail = false;
    $released = fresh($report)->period;
    $second = $reports->run(fresh($report), at('2026-11-02 08:15'));

    return $first === Reports::RESULT_FAILED && $released === $before && $second === Reports::RESULT_SENT && count($mail) === 2
        ?: json_encode([$first, $released, $before, $second, count($mail)]);
});

check('a cancelled report keeps its marker and writes no export', function() use ($reports, $report, $saved, &$mail) {
    $mail = [];
    $exports = (int)ExportRecord::find()->where(['filterId' => $saved->id])->count();
    $before = fresh($report)->period;
    $handler = fn(ReportEvent $e) => $e->isValid = false;
    Event::on(Reports::class, Reports::EVENT_BEFORE_SEND, $handler);
    $result = $reports->run(fresh($report), at('2026-11-09 08:00'));
    Event::off(Reports::class, Reports::EVENT_BEFORE_SEND, $handler);

    return $result === Reports::RESULT_CANCELLED && $mail === [] && fresh($report)->period === $before
        && (int)ExportRecord::find()->where(['filterId' => $saved->id])->count() === $exports
        ?: $result;
});

check('a handler can retitle it, and anyone it adds is checked again', function() use ($reports, $report, &$mail, $nobody, $owner, $colleague) {
    $mail = [];
    $handler = function(ReportEvent $e) use ($nobody) {
        $e->subject = 'Retitled';
        $e->recipients[] = $nobody;
    };
    Event::on(Reports::class, Reports::EVENT_BEFORE_SEND, $handler);
    $result = $reports->run(fresh($report), at('2026-11-09 08:05'));
    Event::off(Reports::class, Reports::EVENT_BEFORE_SEND, $handler);
    $expected = [$owner->email, $colleague->email];
    sort($expected);

    return $result === Reports::RESULT_SENT && recipientsOf($mail) === $expected && (string)$mail[0]->getSubject() === 'Retitled'
        ?: json_encode([$result, recipientsOf($mail)]);
});

check('“only when it matches something” sends nothing for an empty result', function() use ($reports, &$mail, $owner) {
    $mail = [];
    $empty = makeSaved('empty', FilterSet::fromArray([
        'elementType' => Entry::class,
        'criteria' => [['field' => 'id', 'operator' => 'lt', 'value' => 0]],
    ]), $owner);
    $r = makeReport($empty, $owner, ['frequency' => 'daily', 'hour' => 0]);
    $result = $reports->run($r, at('2026-10-07 09:00'));

    return $result === Reports::RESULT_EMPTY && $mail === [] && fresh($r)->period === '2026-10-07' ?: $result;
});

check('“every time” sends an empty result too', function() use ($reports, &$mail, $owner) {
    $mail = [];
    $empty = makeSaved('always', FilterSet::fromArray([
        'elementType' => Entry::class,
        'criteria' => [['field' => 'id', 'operator' => 'lt', 'value' => 0]],
    ]), $owner);
    $r = makeReport($empty, $owner, ['frequency' => 'daily', 'hour' => 0, 'sendWhen' => Report::SEND_ALWAYS]);
    $result = $reports->run($r, at('2026-10-07 09:00'));

    return $result === Reports::RESULT_SENT && count($mail) === 1 && str_contains((string)$mail[0]->getSubject(), 'no results') ?: $result;
});

check('--force sends again in a period that has gone, and records it', function() use ($reports, $report, &$mail) {
    $mail = [];
    $period = fresh($report)->period;
    $result = $reports->run(fresh($report), at('2026-11-09 09:00'), true);

    return $result === Reports::RESULT_SENT && count($mail) === 2 && fresh($report)->period === $period ?: $result;
});

check('a test goes to the person asking, marked, and leaves the marker alone', function() use ($reports, $report, &$mail, $colleague) {
    $mail = [];
    $before = fresh($report);
    $sent = $reports->sendTest(fresh($report), $colleague);
    $after = fresh($report);

    return $sent === 1 && recipientsOf($mail) === [$colleague->email]
        && str_starts_with((string)$mail[0]->getSubject(), '[Test]')
        && str_contains(htmlOf($mail[0]), 'This is a test')
        && $after->period === $before->period
        && $after->lastSentAt?->getTimestamp() === $before->lastSentAt?->getTimestamp()
        && $reports->seen($after) === $reports->seen($before)
        ?: json_encode([$sent, recipientsOf($mail)]);
});

check('a test refuses someone who couldn’t run the filter, and says why', function() use ($reports, $report, &$mail, $nobody) {
    $mail = [];
    $reason = null;
    $sent = $reports->sendTest(fresh($report), $nobody, $reason);

    return $sent === 0 && $mail === [] && is_string($reason) && $reason !== '' ?: json_encode([$sent, $reason]);
});

// ------------------------------------------------------------------------------- who and whom
section('Runs as its owner; goes only to people who could run it');

check('an owner who can no longer run the filter stops it, and the period is not taken', function() use ($reports, $report, &$mail, $nobody, $owner) {
    $mail = [];
    $before = fresh($report)->period;
    Db::update(Reports::TABLE, ['ownerId' => $nobody->id], ['id' => $report->id]);
    $result = $reports->run(fresh($report), at('2026-11-16 08:00'));
    $r = fresh($report);
    Db::update(Reports::TABLE, ['ownerId' => $owner->id], ['id' => $report->id]);

    return $result === Reports::RESULT_REFUSED && $mail === [] && $r->period === $before && $r->lastResult === 'refused' ?: $result;
});

check('a deleted owner stops it too', function() use ($reports, $report, &$mail, $owner) {
    Db::update(Reports::TABLE, ['ownerId' => null], ['id' => $report->id]);
    $result = $reports->run(fresh($report), at('2026-11-16 08:00'));
    Db::update(Reports::TABLE, ['ownerId' => $owner->id], ['id' => $report->id]);

    return $result === Reports::RESULT_REFUSED ?: $result;
});

check('a list of nobody who may receive it is “no recipients”, not a send', function() use ($reports, $report, &$mail, $nobody, $suspended, $owner, $colleague) {
    $mail = [];
    Db::update(Reports::TABLE, ['recipientIds' => json_encode([$nobody->id, $suspended->id])], ['id' => $report->id]);
    $result = $reports->run(fresh($report), at('2026-11-16 08:00'));
    Db::update(Reports::TABLE, ['recipientIds' => json_encode([$owner->id, $colleague->id])], ['id' => $report->id]);

    return $result === Reports::RESULT_NO_RECIPIENTS && $mail === [] ?: $result;
});

check('a suspended admin and a user without Clean Air are refused, each with a reason', function() use ($reports, $saved, $nobody, $suspended, $colleague) {
    [$ok, $refused] = $reports->eligibleRecipients($saved, [$nobody, $suspended, $colleague]);

    return count($ok) === 1 && (int)$ok[0]->id === (int)$colleague->id
        && isset($refused[$nobody->id], $refused[$suspended->id])
        ?: json_encode($refused);
});

check('people, addresses and orders count as personal data; entries and assets don’t', function() use ($plugin) {
    $types = $plugin->types->all();

    return $types['users']->containsPersonalData() && $types['addresses']->containsPersonalData()
        && !$types['entries']->containsPersonalData() && !$types['assets']->containsPersonalData();
});

check('a report of users is never attached or listed; each recipient gets their own copy on Exports', function() use ($reports, &$mail, $owner, $colleague) {
    $mail = [];
    $users = makeSaved('users', FilterSet::fromArray([
        'elementType' => User::class,
        'criteria' => [['field' => 'id', 'operator' => 'between', 'value' => $owner->id, 'value2' => $colleague->id]],
    ]), $owner);
    $r = makeReport($users, $owner, ['frequency' => 'daily', 'hour' => 0, 'recipientIds' => [$owner->id, $colleague->id], 'delivery' => Report::DELIVERY_ATTACH]);
    $result = $reports->run($r, at('2026-10-07 09:00'));

    $attached = array_sum(array_map(fn($m) => count(attachmentsOf($m)), $mail));
    $listed = array_filter($mail, fn($m) => str_contains(htmlOf($m), $owner->username) || str_contains(htmlOf($m), (string)$owner->email . '<'));
    $copies = ExportRecord::find()->where(['filterId' => $users->id])->all();
    $owners = array_map(fn($rec) => (int)$rec->userId, $copies);
    sort($owners);
    $expected = [(int)$owner->id, (int)$colleague->id];
    sort($expected);
    $files = array_filter($copies, fn($rec) => is_file((string)$rec->path));

    return $result === Reports::RESULT_SENT && count($mail) === 2 && $attached === 0 && $listed === []
        && str_contains(htmlOf($mail[0]), 'Open Exports')
        && $owners === $expected && count($files) === 2
        ?: json_encode([$result, count($mail), $attached, count($listed), $owners]);
});

check('an attachment limit of 0 links instead of attaching', function() use ($reports, $report, &$mail, $plugin) {
    $mail = [];
    $plugin->getSettings()->reportAttachmentLimit = 0;
    $result = $reports->run(fresh($report), at('2026-11-23 08:00'));
    $plugin->getSettings()->reportAttachmentLimit = 5;

    return $result === Reports::RESULT_SENT && count($mail) === 2
        && attachmentsOf($mail[0]) === [] && str_contains(htmlOf($mail[0]), 'Open Exports')
        ?: $result;
});

// --------------------------------------------------------------------------------- triggers
section('Triggers');

check('Lite: scheduled reports are off — run() refuses and the fallback queues nothing', function() use ($reports, $report, $plugin, &$mail) {
    $mail = [];
    $plugin->edition = Plugin::EDITION_LITE;
    Craft::$app->getCache()->delete('cleanair:reports:checked');
    $result = $reports->run(fresh($report), at('2026-11-30 08:00'));
    $queued = $reports->queueIfDue(at('2026-11-30 08:00'));
    $plugin->edition = Plugin::EDITION_PRO;

    return $result === Reports::RESULT_DISABLED && $queued === 0 && $mail === [] ?: "$result, $queued";
});

check('the web fallback queues each due report once per period, and throttles itself', function() use ($reports, $report, &$pushed) {
    $pushed = [];
    $cache = Craft::$app->getCache();
    $cache->delete('cleanair:reports:checked');
    $cache->delete('cleanair:reports:queued:' . $report->id . ':2026-W49');
    $first = $reports->queueIfDue(at('2026-11-30 08:00'));
    $ours = array_filter($pushed, fn(SendReport $job) => $job->reportId === (int)$report->id);
    $throttled = $reports->queueIfDue(at('2026-11-30 08:00'));
    $cache->delete('cleanair:reports:checked');
    $again = array_filter($pushed, fn(SendReport $job) => $job->reportId === (int)$report->id);
    $reports->queueIfDue(at('2026-11-30 08:00'));
    $afterAgain = array_filter($pushed, fn(SendReport $job) => $job->reportId === (int)$report->id);

    return $first >= 1 && count($ours) === 1 && $throttled === 0 && count($afterAgain) === count($again)
        ?: json_encode([$first, count($ours), $throttled, count($afterAgain)]);
});

check('the queued job sends through run(), so a duplicate is a no-op', function() use ($report, &$mail) {
    $mail = [];
    // The job reads the real clock; move the marker so "now" is a fresh period for it.
    Db::update(Reports::TABLE, ['period' => null, 'frequency' => 'daily', 'hour' => 0], ['id' => $report->id]);
    $job = new SendReport(['reportId' => $report->id]);
    $job->execute(Craft::$app->getQueue());
    $firstCount = count($mail);
    $job->execute(Craft::$app->getQueue());

    return $firstCount === 2 && count($mail) === 2 ?: "$firstCount then " . count($mail);
});

check('the console command sends one report with --force, and reports what it did', function() use ($saved) {
    exec('php craft cleanair/reports/send --report=' . escapeshellarg($saved->handle) . ' --force 2>&1', $output, $code);
    $text = implode("\n", $output);

    return $code === 0 && str_contains($text, 'Sent.') ?: "exit $code: $text";
});

check('--force without --report is refused', function() {
    exec('php craft cleanair/reports/send --force 2>&1', $output, $code);

    return $code === 64 ?: "exit $code: " . implode("\n", $output);
});

check('the console status command lists the schedule', function() use ($saved) {
    exec('php craft cleanair/reports/status 2>&1', $output, $code);
    $text = implode("\n", $output);

    return $code === 0 && str_contains($text, $saved->handle) && str_contains($text, 'Next due') ?: "exit $code: $text";
});

check('deleting the saved filter deletes its schedule', function() use ($owner, $reports) {
    $doomed = makeSaved('doomed', entryRange(0, 0), $owner);
    $r = makeReport($doomed, $owner);
    Plugin::getInstance()->savedFilters->delete((int)$doomed->id);

    return $reports->getById((int)$r->id) === null;
});

check('the schedule screen and the emails compile', function() {
    $twig = Craft::$app->getView()->getTwig();
    Craft::$app->getView()->setTemplateMode(craft\web\View::TEMPLATE_MODE_CP);

    foreach (['cleanair/reports/edit', 'cleanair/_emails/report', 'cleanair/_emails/report.txt', 'cleanair/saved/index'] as $template) {
        $twig->load($template);
    }

    return true;
});

echo "\n" . str_repeat('=', 60) . "\n";
echo "$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
