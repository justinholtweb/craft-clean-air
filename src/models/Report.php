<?php

namespace justinholtweb\cleanair\models;

use Craft;
use craft\base\Model;
use DateTime;

/**
 * A saved filter's email schedule: when it runs, who gets it, and how.
 *
 * One per saved filter, stored in `cleanair_reports` with its own marker — the period it last
 * claimed and the element IDs it reported — so several filters can each run on their own clock
 * without any of them knowing about the others.
 *
 * The report runs as its **owner**: whoever last saved the schedule. The owner's permissions
 * decide what it may read, and every recipient has to be someone who could have run the same
 * filter and exported it themselves.
 */
class Report extends Model
{
    public const FREQUENCY_DAILY = 'daily';
    public const FREQUENCY_WEEKLY = 'weekly';
    public const FREQUENCY_MONTHLY = 'monthly';

    /** Send every time. */
    public const SEND_ALWAYS = 'always';
    /** Only when the filter matches something. */
    public const SEND_RESULTS = 'results';
    /** Only when it matches something the last report didn't. */
    public const SEND_NEW = 'new';

    /** Attach the file to the email (never for personal data — see BaseType::containsPersonalData()). */
    public const DELIVERY_ATTACH = 'attach';
    /** Put a copy on each recipient's Exports screen and link to it. */
    public const DELIVERY_LINK = 'link';

    public ?int $id = null;
    public ?string $uid = null;

    public ?int $filterId = null;

    /** @var int|null The user it runs as. Null once that account is deleted, and then it stops. */
    public ?int $ownerId = null;

    public bool $enabled = true;

    public string $frequency = self::FREQUENCY_WEEKLY;

    /** ISO weekday, 1 (Monday) to 7 (Sunday), for weekly reports. */
    public int $weekday = 1;

    /** Day of the month, 1–28 so every month has one, for monthly reports. */
    public int $monthDay = 1;

    /** Hour of the day, 0–23, in the system time zone. */
    public int $hour = 8;

    /** @var int[] Craft user IDs. Reports only ever go to control panel users. */
    public array $recipientIds = [];

    public string $format = 'csv';

    public string $sendWhen = self::SEND_RESULTS;

    public string $delivery = self::DELIVERY_ATTACH;

    // The marker, read-only from the model's point of view: Reports writes these.

    /** The period last claimed: `2026-10-09`, `2026-W41` or `2026-10`. */
    public ?string $period = null;
    public ?DateTime $lastRunAt = null;
    public ?DateTime $lastSentAt = null;
    public ?string $lastResult = null;
    public int $lastCount = 0;
    public int $lastNewCount = 0;

    public function setAttributes($values, $safeOnly = true): void
    {
        // Form posts are strings, and an element select posts `''` when it's empty.
        if ($values) {
            foreach (['weekday', 'monthDay', 'hour'] as $key) {
                if (isset($values[$key]) && is_string($values[$key])) {
                    $values[$key] = (int)$values[$key];
                }
            }

            if (array_key_exists('recipientIds', $values)) {
                $values['recipientIds'] = self::cleanIds($values['recipientIds']);
            }

            if (isset($values['enabled']) && !is_bool($values['enabled'])) {
                $values['enabled'] = in_array(strtolower((string)$values['enabled']), ['1', 'on', 'yes', 'true'], true);
            }
        }

        parent::setAttributes($values, $safeOnly);
    }

    public function rules(): array
    {
        return [
            [['frequency'], 'in', 'range' => [self::FREQUENCY_DAILY, self::FREQUENCY_WEEKLY, self::FREQUENCY_MONTHLY]],
            [['weekday'], 'integer', 'min' => 1, 'max' => 7],
            [['monthDay'], 'integer', 'min' => 1, 'max' => 28],
            [['hour'], 'integer', 'min' => 0, 'max' => 23],
            [['sendWhen'], 'in', 'range' => [self::SEND_ALWAYS, self::SEND_RESULTS, self::SEND_NEW]],
            [['delivery'], 'in', 'range' => [self::DELIVERY_ATTACH, self::DELIVERY_LINK]],
            [['format'], 'string', 'max' => 16],
            [['enabled'], 'boolean'],
            [['recipientIds'], 'validateRecipientIds', 'skipOnEmpty' => false],
        ];
    }

    public function validateRecipientIds(string $attribute): void
    {
        if ($this->recipientIds === []) {
            $this->addError($attribute, Craft::t('cleanair', 'Choose at least one person to send it to.'));
        }
    }

    /** "Weekly, Mondays at 08:00" — for lists and the console. */
    public function describe(): string
    {
        $time = sprintf('%02d:00', $this->hour);

        return match ($this->frequency) {
            self::FREQUENCY_DAILY => Craft::t('cleanair', 'Daily at {time}', ['time' => $time]),
            self::FREQUENCY_MONTHLY => Craft::t('cleanair', 'Monthly on day {day} at {time}', ['day' => $this->monthDay, 'time' => $time]),
            default => Craft::t('cleanair', 'Weekly on {day} at {time}', [
                'day' => Craft::$app->getLocale()->getWeekDayName($this->weekday % 7),
                'time' => $time,
            ]),
        };
    }

    /**
     * @return int[]
     */
    public static function cleanIds(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : ($value === '' ? [] : explode(',', $value));
        }

        $ids = array_map('intval', array_filter((array)$value, fn($id) => is_numeric($id)));

        return array_values(array_unique(array_filter($ids, fn(int $id) => $id > 0)));
    }
}
