<?php

namespace justinholtweb\cleanair\services;

use Craft;
use craft\db\Migration;
use craft\db\Query as DbQuery;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\UrlHelper;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use justinholtweb\cleanair\events\ReportEvent;
use justinholtweb\cleanair\helpers\Mailer;
use justinholtweb\cleanair\migrations\Install;
use justinholtweb\cleanair\models\Edition;
use justinholtweb\cleanair\models\Report;
use justinholtweb\cleanair\models\SavedFilter;
use justinholtweb\cleanair\Plugin;
use justinholtweb\cleanair\queue\SendReport;
use Throwable;
use yii\base\Component;

/**
 * Scheduled reports: a saved filter's results, emailed daily, weekly or monthly.
 *
 * A port of the family's scheduled-digest reference (Yarn's `services\Digest`; see "Scheduled
 * digests" in Yarn's CLAUDE.md). The one structural change: Yarn has one digest and one marker
 * row, Clean Air has any number of reports and **one marker row per report**. Each row carries
 * its own schedule, its own claimed period and its own "seen" list, so reports never wait on or
 * claim for each other.
 *
 * Everything above "What Clean Air reports" is the generic half — schedule, durable marker, the
 * claim that makes a send happen once, the fallback trigger. Below it is the part specific to
 * Clean Air: who a report may run as, who may receive it, and what the email says.
 *
 * Three ways in, one way through:
 *
 * - `php craft cleanair/reports/send` from cron — the recommended route, every 15–60 minutes;
 * - the end of a web request, at most every five minutes, which queues {@see SendReport};
 * - "Send me a test now" on the schedule screen, which never touches the marker.
 *
 * The scheduled ones end in {@see run()}, which is idempotent per report: the first caller to
 * move a report's marker onto the current period sends, everybody after it finds the period
 * taken. Nothing here hangs off `Gc::EVENT_RUN` — garbage collection runs on a dice roll, and a
 * report that arrives on a dice roll is not a schedule.
 */
class Reports extends Component
{
    /** @see ReportEvent Cancel it, or change the recipients, subject or variables. */
    public const EVENT_BEFORE_SEND = 'beforeSend';

    public const TABLE = Install::TABLE_REPORTS;

    public const RESULT_SENT = 'sent';
    public const RESULT_DISABLED = 'disabled';
    public const RESULT_NO_RECIPIENTS = 'noRecipients';
    public const RESULT_REFUSED = 'refused';
    public const RESULT_NOT_DUE = 'notDue';
    public const RESULT_ALREADY_SENT = 'alreadySent';
    public const RESULT_EMPTY = 'empty';
    public const RESULT_NOTHING_NEW = 'nothingNew';
    public const RESULT_CANCELLED = 'cancelled';
    public const RESULT_FAILED = 'failed';

    /** How often the web fallback looks at the schedules at all. */
    public const WEB_CHECK_SECONDS = 300;

    /**
     * Most element IDs remembered per report for "what's new". Past this the comparison isn't
     * made — the email says so — rather than storing a quarter of a million IDs in one row.
     */
    public const SEEN_LIMIT = 50000;

    /** Rows listed in the email body. The file has the rest. */
    public const PREVIEW_ROWS = 10;

    /** Stored in `seen` when the result set was too big to remember. */
    private const SEEN_OVERFLOW = '"*"';

    private const WEB_CHECK_KEY = 'cleanair:reports:checked';
    private const QUEUED_KEY = 'cleanair:reports:queued:';

    // ------------------------------------------------------------------------------- schedule

    /** Now, in the system time zone — the one report hours are set in. */
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone(Craft::$app->getTimeZone()));
    }

    /**
     * The period a moment falls in for a report: `2026-10-09` daily, `2026-W41` weekly (ISO
     * week — `o`, not `Y`: 2027-01-01 is `2026-W53`), `2026-10` monthly. One send per key, ever.
     */
    public function periodKey(Report $report, DateTimeInterface $now): string
    {
        $now = $this->inSystemTime($now);

        return match ($report->frequency) {
            Report::FREQUENCY_DAILY => $now->format('Y-m-d'),
            Report::FREQUENCY_MONTHLY => $now->format('Y-m'),
            default => $now->format('o-\WW'),
        };
    }

    /** When the report for the period containing `$now` becomes due. */
    public function dueAt(Report $report, DateTimeInterface $now): DateTimeImmutable
    {
        $now = $this->inSystemTime($now);

        if ($report->frequency === Report::FREQUENCY_WEEKLY) {
            $now = $now->setISODate((int)$now->format('o'), (int)$now->format('W'), $report->weekday);
        } elseif ($report->frequency === Report::FREQUENCY_MONTHLY) {
            $now = $now->setDate((int)$now->format('Y'), (int)$now->format('n'), $report->monthDay);
        }

        return $now->setTime($report->hour, 0);
    }

    /**
     * Whether a scheduled run at `$now` would send (or at least try to). "At or after" the due
     * time, so a missed hour still sends later in the same period.
     */
    public function isDue(Report $report, ?DateTimeInterface $now = null): bool
    {
        $now = $this->inSystemTime($now ?? $this->now());

        return $report->enabled
            && $now >= $this->dueAt($report, $now)
            && $report->period !== $this->periodKey($report, $now);
    }

    /** When the report will next go — now, when it is due and hasn't gone. */
    public function nextDueAt(Report $report, ?DateTimeInterface $now = null): ?DateTimeImmutable
    {
        if (!$report->enabled) {
            return null;
        }

        $now = $this->inSystemTime($now ?? $this->now());

        if ($report->period !== $this->periodKey($report, $now)) {
            $due = $this->dueAt($report, $now);

            return $due > $now ? $due : $now;
        }

        $next = match ($report->frequency) {
            Report::FREQUENCY_DAILY => $now->modify('+1 day'),
            // The first of next month, so a 31st can't skip February.
            Report::FREQUENCY_MONTHLY => $now->modify('first day of next month'),
            default => $now->modify('+1 week'),
        };

        return $this->dueAt($report, $next);
    }

    /**
     * The marker a newly saved schedule starts from. A schedule saved after this period's due
     * time starts as if this period had gone, so saving "weekly on Monday" on a Wednesday sends
     * next Monday rather than straight away. "Send me a test now" is for straight away.
     */
    public function initialPeriod(Report $report, ?DateTimeInterface $now = null): ?string
    {
        $now = $this->inSystemTime($now ?? $this->now());

        return $now >= $this->dueAt($report, $now) ? $this->periodKey($report, $now) : null;
    }

    // --------------------------------------------------------------------------- the triggers

    /**
     * The scheduled send for one report. Idempotent: call it as often as you like, it sends once
     * per period.
     *
     * @param bool $force Ignore the schedule and the "already sent" marker — `--force` on the
     *                    console command. Still records the send, so the next scheduled one
     *                    reports what is new since *this*.
     * @return string One of the `RESULT_*` constants.
     */
    public function run(Report $report, ?DateTimeInterface $now = null, bool $force = false): string
    {
        $now = $this->inSystemTime($now ?? $this->now());

        if (!Edition::allowsScheduledReports(Plugin::getInstance()->isPro())) {
            return self::RESULT_DISABLED;
        }

        if (!$force && !$report->enabled) {
            return self::RESULT_DISABLED;
        }

        if (!$force && $now < $this->dueAt($report, $now)) {
            return self::RESULT_NOT_DUE;
        }

        $period = $this->periodKey($report, $now);
        $previous = $report->period;

        if (!$force && $previous === $period) {
            return self::RESULT_ALREADY_SENT;
        }

        // Who it runs as, and who may get it, are asked every time: permissions change, and a
        // schedule set up last spring must not outlive its owner's access.
        $saved = $this->savedFilter($report);
        $owner = $this->owner($report);
        $reason = null;

        if ($saved === null || $owner === null || !$this->canRun($saved, $owner, $reason)) {
            Craft::warning(sprintf(
                'Report %d was not sent: %s',
                (int)$report->id,
                $reason ?? 'its saved filter or its owner is gone',
            ), Plugin::LOG_CATEGORY);

            return $this->record($report, null, null, self::RESULT_REFUSED);
        }

        [$recipients] = $this->eligibleRecipients($saved, $this->users($report->recipientIds));

        if ($recipients === []) {
            return $this->record($report, null, null, self::RESULT_NO_RECIPIENTS);
        }

        if (!$force && !$this->claim($report, $period, $previous)) {
            return self::RESULT_ALREADY_SENT;
        }

        try {
            [$count, $ids] = $this->collect($saved);
        } catch (Throwable $e) {
            $this->release($report, $period, $previous);
            Craft::error("Could not run report {$report->id}: " . $e->getMessage(), Plugin::LOG_CATEGORY);

            return $this->record($report, null, null, self::RESULT_FAILED);
        }

        $seen = $this->seen($report);
        $new = $this->newIds($ids, $seen);

        if ($count === 0 && $report->sendWhen !== Report::SEND_ALWAYS) {
            return $this->record($report, $period, $ids, self::RESULT_EMPTY);
        }

        // `$new === null` means "can't tell" — too many to compare — and that is not "nothing new".
        if ($report->sendWhen === Report::SEND_NEW && $new === []) {
            return $this->record($report, $period, $ids, self::RESULT_NOTHING_NEW);
        }

        try {
            $delivered = $this->deliver($report, $saved, $owner, $recipients, $count, $new, false);
        } catch (Throwable $e) {
            Craft::error("Could not send report {$report->id}: " . $e->getMessage(), Plugin::LOG_CATEGORY);
            $delivered = 0;
        }

        if ($delivered === null) {
            $this->release($report, $period, $previous);

            return $this->record($report, null, null, self::RESULT_CANCELLED);
        }

        if ($delivered === 0) {
            // Give the period back, so the next run tries again rather than the week going by
            // without a report because the mail server was down for five minutes on Monday.
            $this->release($report, $period, $previous);

            return $this->record($report, null, null, self::RESULT_FAILED);
        }

        return $this->record($report, $period, $ids, self::RESULT_SENT, $count, $new === null ? $count : count($new));
    }

    /**
     * Every enabled report, each through {@see run()}.
     *
     * @return array<int, string> Report ID => result.
     */
    public function runAll(?DateTimeInterface $now = null): array
    {
        $results = [];

        foreach ($this->all() as $report) {
            if (!$report->enabled) {
                continue;
            }
            $results[(int)$report->id] = $this->run($report, $now);
        }

        return $results;
    }

    /**
     * "Send me a test now". Sends what the report would say to the person asking, run with
     * *their* permissions, and marked as a test. Leaves the marker alone — a test must never
     * stop the real one going out, or change what the real one calls new.
     *
     * @return int|null How many it was delivered to (0 or 1), or null when an event cancelled it.
     */
    public function sendTest(Report $report, User $user, ?string &$reason = null): ?int
    {
        $saved = $this->savedFilter($report);

        if ($saved === null || !$this->canRun($saved, $user, $reason)) {
            return 0;
        }

        [$recipients, $refused] = $this->eligibleRecipients($saved, [$user]);

        if ($recipients === []) {
            $reason = reset($refused) ?: null;
            return 0;
        }

        [$count, $ids] = $this->collect($saved);

        return $this->deliver($report, $saved, $user, $recipients, $count, $this->newIds($ids, $this->seen($report)), true);
    }

    /**
     * The fallback for sites with no cron job: called at the end of web requests, looks at the
     * schedules at most every {@see WEB_CHECK_SECONDS}, and queues {@see SendReport} for each
     * report that is due.
     *
     * Cheap on purpose — one cache read on almost every request, one small table read every
     * five minutes. The job does the work; `run()` decides, so a duplicate job is a no-op.
     *
     * @return int How many reports were queued.
     */
    public function queueIfDue(?DateTimeInterface $now = null): int
    {
        $plugin = Plugin::getInstance();

        if (!Edition::allowsScheduledReports($plugin->isPro()) || !$plugin->getSettings()->reportWebTrigger) {
            return 0;
        }

        $cache = Craft::$app->getCache();

        // `add()` only writes when the key is absent, so of several requests landing together
        // one goes on to look at the schedules.
        if (!$cache->add(self::WEB_CHECK_KEY, 1, self::WEB_CHECK_SECONDS)) {
            return 0;
        }

        $now = $this->inSystemTime($now ?? $this->now());
        $queued = 0;

        foreach ($this->all() as $report) {
            if (!$this->isDue($report, $now)) {
                continue;
            }

            if (!$cache->add(self::QUEUED_KEY . $report->id . ':' . $this->periodKey($report, $now), 1, 3600)) {
                continue;
            }

            Craft::$app->getQueue()->push(new SendReport(['reportId' => $report->id]));
            $queued++;
        }

        return $queued;
    }

    // ------------------------------------------------------------------------- storage

    public function getById(int $id): ?Report
    {
        $row = (new DbQuery())->from([self::TABLE])->where(['id' => $id])->one();

        return $row ? $this->toModel($row) : null;
    }

    public function getByFilterId(int $filterId): ?Report
    {
        $row = (new DbQuery())->from([self::TABLE])->where(['filterId' => $filterId])->one();

        return $row ? $this->toModel($row) : null;
    }

    /** @return Report[] */
    public function all(): array
    {
        $reports = [];

        foreach ((new DbQuery())->from([self::TABLE])->orderBy(['id' => SORT_ASC])->all() as $row) {
            $reports[] = $this->toModel($row);
        }

        return $reports;
    }

    /**
     * Saves a schedule's settings. Never the marker — except on a new schedule, or when the
     * timing changes, where the marker is set by {@see initialPeriod()}.
     */
    public function save(Report $report): bool
    {
        if (!$report->validate()) {
            return false;
        }

        $existing = $report->id ? $this->getById($report->id) : null;
        $timingChanged = $existing === null
            || $existing->frequency !== $report->frequency
            || $existing->weekday !== $report->weekday
            || $existing->monthDay !== $report->monthDay
            || $existing->hour !== $report->hour;

        $columns = [
            'filterId' => $report->filterId,
            'ownerId' => $report->ownerId,
            'enabled' => $report->enabled,
            'frequency' => $report->frequency,
            'weekday' => $report->weekday,
            'monthDay' => $report->monthDay,
            'hour' => $report->hour,
            'recipientIds' => json_encode(array_values($report->recipientIds)),
            'format' => $report->format,
            'sendWhen' => $report->sendWhen,
            'delivery' => $report->delivery,
        ];

        if ($timingChanged) {
            $report->period = $this->initialPeriod($report);
            $columns['period'] = $report->period;
        }

        if ($existing === null) {
            Db::insert(self::TABLE, $columns);
            $report->id = (int)Craft::$app->getDb()->getLastInsertID();
            $report->uid = (new DbQuery())->select(['uid'])->from([self::TABLE])->where(['id' => $report->id])->scalar() ?: null;
        } else {
            Db::update(self::TABLE, $columns, ['id' => $report->id]);
        }

        return true;
    }

    public function delete(int $id): bool
    {
        return Db::delete(self::TABLE, ['id' => $id]) > 0;
    }

    /**
     * Moves the report's marker onto `$period`, but only if it still says `$previous`. Of two
     * runs racing for the same period, the database lets exactly one of them through.
     */
    private function claim(Report $report, string $period, ?string $previous): bool
    {
        $claimed = Db::update(
            self::TABLE,
            ['period' => $period],
            ['id' => $report->id, 'period' => $previous],
        ) === 1;

        if ($claimed) {
            $report->period = $period;
        }

        return $claimed;
    }

    /** Hands a claimed period back after a failure, so the next run tries again. */
    private function release(Report $report, string $period, ?string $previous): void
    {
        Db::update(self::TABLE, ['period' => $previous], ['id' => $report->id, 'period' => $period]);
        $report->period = $previous;
    }

    /**
     * Writes down how a run went. `$period` and `$ids` are null when nothing should change but
     * the result — a failure leaves the period and the "seen" list as they were.
     *
     * @param int[]|null|false $ids False for "too many to remember".
     */
    private function record(Report $report, ?string $period, array|false|null $ids, string $result, ?int $count = null, ?int $newCount = null): string
    {
        $now = new DateTimeImmutable();
        $columns = [
            'lastRunAt' => Db::prepareDateForDb($now),
            'lastResult' => $result,
        ];

        if ($period !== null) {
            $columns['period'] = $period;
            $report->period = $period;
        }

        if ($ids !== null) {
            $columns['seen'] = $ids === false ? self::SEEN_OVERFLOW : json_encode($ids);
        }

        if ($result === self::RESULT_SENT) {
            $columns['lastSentAt'] = Db::prepareDateForDb($now);
            $columns['lastCount'] = (int)$count;
            $columns['lastNewCount'] = (int)$newCount;
            $report->lastCount = (int)$count;
            $report->lastNewCount = (int)$newCount;
        }

        Db::update(self::TABLE, $columns, ['id' => $report->id]);
        $report->lastResult = $result;

        if ($result === self::RESULT_FAILED) {
            Craft::warning("Report {$report->id} was not sent; the next run will try again.", Plugin::LOG_CATEGORY);
        } else {
            Craft::info("Report {$report->id}: $result.", Plugin::LOG_CATEGORY);
        }

        return $result;
    }

    /**
     * What the last report contained: a list of IDs, `[]` before the first one, or null when it
     * was too big to remember.
     *
     * @return int[]|null
     */
    public function seen(Report $report): ?array
    {
        $raw = (new DbQuery())->select(['seen'])->from([self::TABLE])->where(['id' => $report->id])->scalar();

        if ($raw === self::SEEN_OVERFLOW) {
            return null;
        }

        $seen = json_decode((string)$raw, true);

        return is_array($seen) ? array_map('intval', $seen) : [];
    }

    /**
     * @param int[]|false $ids
     * @param int[]|null $seen
     * @return int[]|null Null when the two can't be compared.
     */
    private function newIds(array|false $ids, ?array $seen): ?array
    {
        if ($ids === false || $seen === null) {
            return null;
        }

        return array_values(array_diff($ids, $seen));
    }

    private function toModel(array $row): Report
    {
        return new Report([
            'id' => (int)$row['id'],
            'uid' => $row['uid'] ?? null,
            'filterId' => (int)$row['filterId'],
            'ownerId' => isset($row['ownerId']) ? (int)$row['ownerId'] : null,
            'enabled' => (bool)$row['enabled'],
            'frequency' => (string)$row['frequency'],
            'weekday' => (int)$row['weekday'],
            'monthDay' => (int)$row['monthDay'],
            'hour' => (int)$row['hour'],
            'recipientIds' => Report::cleanIds((string)($row['recipientIds'] ?? '')),
            'format' => (string)$row['format'],
            'sendWhen' => (string)$row['sendWhen'],
            'delivery' => (string)$row['delivery'],
            'period' => isset($row['period']) && $row['period'] !== '' ? (string)$row['period'] : null,
            'lastRunAt' => DateTimeHelper::toDateTime($row['lastRunAt'] ?? null) ?: null,
            'lastSentAt' => DateTimeHelper::toDateTime($row['lastSentAt'] ?? null) ?: null,
            'lastResult' => $row['lastResult'] ?? null,
            'lastCount' => (int)($row['lastCount'] ?? 0),
            'lastNewCount' => (int)($row['lastNewCount'] ?? 0),
        ]);
    }

    /** Shared by `Install` and the migration that adds the table to an existing install. */
    public static function createTable(Migration $migration): void
    {
        $migration->createTable(self::TABLE, [
            'id' => $migration->primaryKey(),
            'filterId' => $migration->integer()->notNull(),
            // Who it runs as. Null once their account is deleted, and then it stops.
            'ownerId' => $migration->integer(),
            'enabled' => $migration->boolean()->notNull()->defaultValue(true),
            'frequency' => $migration->string(16)->notNull()->defaultValue('weekly'),
            'weekday' => $migration->tinyInteger()->notNull()->defaultValue(1),
            'monthDay' => $migration->tinyInteger()->notNull()->defaultValue(1),
            'hour' => $migration->tinyInteger()->notNull()->defaultValue(8),
            // JSON list of user IDs.
            'recipientIds' => $migration->text(),
            'format' => $migration->string(16)->notNull()->defaultValue('csv'),
            'sendWhen' => $migration->string(16)->notNull()->defaultValue('results'),
            'delivery' => $migration->string(16)->notNull()->defaultValue('attach'),
            // The marker. The period last claimed: `2026-10-09`, `2026-W41` or `2026-10`.
            'period' => $migration->string(32),
            // JSON list of the element IDs in the last report, or "*" when there were too many.
            'seen' => $migration->mediumText(),
            'lastRunAt' => $migration->dateTime(),
            'lastSentAt' => $migration->dateTime(),
            'lastResult' => $migration->string(32),
            'lastCount' => $migration->integer()->notNull()->defaultValue(0),
            'lastNewCount' => $migration->integer()->notNull()->defaultValue(0),
            'dateCreated' => $migration->dateTime()->notNull(),
            'dateUpdated' => $migration->dateTime()->notNull(),
            'uid' => $migration->uid(),
        ]);

        $migration->createIndex(null, self::TABLE, ['filterId'], true);
        $migration->addForeignKey(null, self::TABLE, ['filterId'], Install::TABLE_FILTERS, ['id'], 'CASCADE', null);
        $migration->addForeignKey(null, self::TABLE, ['ownerId'], '{{%users}}', ['id'], 'SET NULL', null);
    }

    // ===================================================================== What Clean Air reports
    //
    // Everything below is Clean Air's: who a report runs as, who it may go to, what it collects
    // and what the email says.

    /**
     * Whether `$user` may run this saved filter and export it — exactly what the builder and
     * the export action would ask of them in the control panel. Reports run without a session,
     * so the query builder itself checks nothing (see CLAUDE.md, "Where authorization lives");
     * this is the gate instead.
     */
    public function canRun(SavedFilter $saved, User $user, ?string &$reason = null): bool
    {
        $plugin = Plugin::getInstance();
        $name = $user->getName();

        if (!$this->isActive($user)) {
            $reason = Craft::t('cleanair', '{user}’s account isn’t active.', ['user' => $name]);
            return false;
        }

        if (!$plugin->permissions->canView($user) || !$plugin->permissions->canExport($user)) {
            $reason = Craft::t('cleanair', '{user} can’t use Clean Air or export from it.', ['user' => $name]);
            return false;
        }

        if (!$plugin->permissions->canReadFilter($saved, $user)) {
            $reason = Craft::t('cleanair', '{user} can’t see this saved filter.', ['user' => $name]);
            return false;
        }

        $type = $plugin->types->forElementType($saved->elementType);

        if ($type === null || !isset($plugin->types->available($user)[$type->key()])) {
            $reason = Craft::t('cleanair', '{user} can’t filter this element type.', ['user' => $name]);
            return false;
        }

        if (!$plugin->types->isAllowedSource($type, $saved->filter->source, $user)) {
            $reason = Craft::t('cleanair', '{user} can’t filter this source.', ['user' => $name]);
            return false;
        }

        return true;
    }

    /**
     * The users a report may go to: control panel users who could run the filter and export it
     * themselves, with an email address. A report never tells anyone something they couldn't
     * have found out in Clean Air.
     *
     * @param User[] $users
     * @return array{0: User[], 1: array<int, string>} The eligible users, and why each other one isn't.
     */
    public function eligibleRecipients(SavedFilter $saved, array $users): array
    {
        $eligible = [];
        $refused = [];

        foreach ($users as $user) {
            $reason = null;

            if (!$user->email) {
                $refused[(int)$user->id] = Craft::t('cleanair', '{user} has no email address.', ['user' => $user->getName()]);
                continue;
            }

            if (!$this->canRun($saved, $user, $reason)) {
                $refused[(int)$user->id] = (string)$reason;
                continue;
            }

            $eligible[(int)$user->id] = $user;
        }

        return [array_values($eligible), $refused];
    }

    /**
     * How many elements the filter matches now, and which — or `false` for the IDs when there are
     * more than {@see SEEN_LIMIT}, which is too many to remember.
     *
     * @return array{0: int, 1: int[]|false}
     */
    public function collect(SavedFilter $saved): array
    {
        $query = Plugin::getInstance()->query;
        $count = (int)$query->build($saved->filter)->count();

        if ($count > self::SEEN_LIMIT) {
            return [$count, false];
        }

        $ids = array_map('intval', $query->build($saved->filter)->limit(null)->offset(null)->ids());
        sort($ids);

        return [$count, $ids];
    }

    public function savedFilter(Report $report): ?SavedFilter
    {
        return $report->filterId ? Plugin::getInstance()->savedFilters->getById($report->filterId) : null;
    }

    public function owner(Report $report): ?User
    {
        return $report->ownerId ? $this->users([$report->ownerId])[0] ?? null : null;
    }

    /**
     * @param int[] $ids
     * @return User[]
     */
    public function users(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        // Every status, so a suspended recipient is refused with a reason rather than vanishing.
        /** @var User[] $users */
        $users = User::find()->id($ids)->status(null)->all();

        return $users;
    }

    private function isActive(User $user): bool
    {
        return $user->getStatus() === User::STATUS_ACTIVE && !$user->locked;
    }

    /**
     * Exports the filter as the owner, then emails each recipient — the file attached, or a copy
     * waiting on their own Exports screen. Null when an event handler cancelled it.
     *
     * @param User[] $recipients
     * @param int[]|null $new
     */
    private function deliver(Report $report, SavedFilter $saved, User $owner, array $recipients, int $count, ?array $new, bool $test): ?int
    {
        $plugin = Plugin::getInstance();
        $type = $plugin->filters->type($saved->filter);
        $personal = $type->containsPersonalData();
        $attach = $report->delivery === Report::DELIVERY_ATTACH && !$personal;

        $event = new ReportEvent([
            'report' => $report,
            'savedFilter' => $saved,
            'recipients' => $recipients,
            'subject' => $this->subject($saved, $count, $test),
            'variables' => $this->variables($report, $saved, $count, $new, $personal, $test),
            'isTest' => $test,
        ]);
        $this->trigger(self::EVENT_BEFORE_SEND, $event);

        if (!$event->isValid) {
            return null;
        }

        // Whoever a handler added still has to be someone who could have run it.
        [$recipients] = $this->eligibleRecipients($saved, $event->recipients);

        if ($recipients === []) {
            return 0;
        }

        $format = isset($plugin->exports->availableFormats()[$report->format]) ? $report->format : 'csv';
        $export = $plugin->exports->run($saved->filter, $format, $saved->name, (int)$owner->id, $saved->id);

        // 0 MB never attaches; otherwise anything over the limit goes on the Exports screen.
        $limitMb = $plugin->getSettings()->reportAttachmentLimit;
        $fits = $limitMb > 0 && (int)$export->filesize <= $limitMb * 1024 * 1024;
        $tooLarge = $attach && $limitMb > 0 && !$fits;
        $attach = $attach && $fits;

        $variables = array_merge($event->variables, [
            'attached' => $attach,
            'tooLargeToAttach' => $tooLarge,
            'fileName' => $export->filename,
            'rowCount' => (int)$export->rowCount,
        ]);

        $sent = 0;

        foreach ($recipients as $recipient) {
            if ($attach) {
                $sent += Mailer::send([(string)$recipient->email], $event->subject, 'cleanair/_emails/report', $variables, [
                    (string)$export->path => (string)$export->filename,
                ]);
                continue;
            }

            // Their own copy: an export file is downloadable only by the person it belongs to.
            $copy = (int)$recipient->id === (int)$owner->id ? $export : $plugin->exports->copyFor($export, (int)$recipient->id);

            $sent += Mailer::send([(string)$recipient->email], $event->subject, 'cleanair/_emails/report', array_merge($variables, [
                'downloadUrl' => UrlHelper::cpUrl('cleanair/exports'),
                'exportId' => $copy->id,
            ]));
        }

        return $sent;
    }

    private function subject(SavedFilter $saved, int $count, bool $test): string
    {
        $subject = Craft::t('cleanair', '{name}: {count, plural, =0{no results} =1{one result} other{# results}}', [
            'name' => $saved->name,
            'count' => $count,
        ]);

        return ($test ? Craft::t('cleanair', '[Test]') . ' ' : '') . 'Clean Air — ' . $subject;
    }

    /**
     * @param int[]|null $new
     * @return array<string, mixed>
     */
    private function variables(Report $report, SavedFilter $saved, int $count, ?array $new, bool $personal, bool $test): array
    {
        $plugin = Plugin::getInstance();
        $type = $plugin->filters->type($saved->filter);
        $items = [];

        // People, addresses and orders stay out of the email body entirely: the file is where
        // they are, behind a login.
        if (!$personal && $count > 0) {
            $query = $plugin->query->build($saved->filter);

            if ($new !== null && $new !== []) {
                $query->andWhere(['elements.id' => array_slice($new, 0, self::PREVIEW_ROWS)]);
            }

            foreach ($query->limit(self::PREVIEW_ROWS)->all() as $element) {
                $items[] = [
                    'label' => (string)$element->getUiLabel(),
                    'url' => $element->getCpEditUrl(),
                    'isNew' => $new !== null && in_array((int)$element->id, $new, true),
                ];
            }
        }

        return [
            'filterName' => $saved->name,
            'typeLabel' => $type->label(),
            'isTest' => $test,
            'count' => $count,
            'newCount' => $new === null ? null : count($new),
            'firstReport' => $report->lastSentAt === null,
            'tooManyToCompare' => $new === null,
            'since' => $report->lastSentAt,
            'schedule' => $report->describe(),
            'personal' => $personal,
            'items' => $items,
            'more' => max(0, ($new !== null && $new !== [] ? count($new) : $count) - count($items)),
            'previewIsNew' => $new !== null && $new !== [],
            'filterUrl' => $saved->getCpUrl(),
            'scheduleUrl' => UrlHelper::cpUrl('cleanair/saved/' . $saved->id . '/report'),
            'downloadUrl' => UrlHelper::cpUrl('cleanair/exports'),
            'exportId' => null,
        ];
    }

    // ---------------------------------------------------------------------------------- misc

    private function inSystemTime(DateTimeInterface $moment): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($moment)
            ->setTimezone(new DateTimeZone(Craft::$app->getTimeZone()));
    }
}
