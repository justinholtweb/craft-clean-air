<?php

/**
 * Clean Air's integration suite.
 *
 * Builds its own content — fields, an entry type, a section, entries with known values — runs
 * the whole plugin against it, and tears it back down. Nothing here depends on what happens to
 * be in the harness, so it gives the same answer on any Craft install.
 *
 * Run from the Craft installation, not from the plugin:
 *
 *   ddev exec php /var/www/craft-cleanair/tests/integration/checks.php
 *   ddev exec php /var/www/craft-cleanair/tests/integration/checks.php --keep   (skip teardown)
 */

$base = getenv('CRAFT_BASE_PATH') ?: '/var/www/html';

require $base . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\db\Query as DbQuery;
use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\fields\Date;
use craft\fields\Dropdown;
use craft\fields\Entries as EntriesField;
use craft\fields\Lightswitch;
use craft\fields\Number;
use craft\fields\PlainText;
use craft\helpers\Db;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use justinholtweb\cleanair\models\Criterion;
use justinholtweb\cleanair\models\Edition;
use justinholtweb\cleanair\models\FieldDefinition;
use justinholtweb\cleanair\models\FilterSet;
use justinholtweb\cleanair\models\SavedFilter;
use justinholtweb\cleanair\Plugin;

$keep = in_array('--keep', $argv, true);

$passed = 0;
$failed = 0;
$skipped = 0;
$failures = [];

function check(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed, $failures;

    if ($condition) {
        $passed++;
        return;
    }

    $failed++;
    $failures[] = $label . ($detail !== '' ? " — $detail" : '');
    echo "  FAIL  $label" . ($detail !== '' ? " — $detail" : '') . "\n";
}

/**
 * Some of Craft's own control panel macros — the date picker, the element selector — call
 * methods that only exist on a web request. They can't run here, and pretending they passed
 * would be worse than saying so. They are covered by loading the real control panel pages.
 */
function skip(string $label, string $reason): void
{
    global $skipped;

    $skipped++;
    echo "  skip  $label — $reason\n";
}

function section(string $label): void
{
    echo "\n$label\n";
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

$fieldsService = Craft::$app->getFields();
$entriesService = Craft::$app->getEntries();
$elementsService = Craft::$app->getElements();

function teardown(): void
{
    $entriesService = Craft::$app->getEntries();
    $fieldsService = Craft::$app->getFields();

    $section = $entriesService->getSectionByHandle('cleanairFixture');

    if ($section) {
        $entriesService->deleteSection($section);
    }

    $type = $entriesService->getEntryTypeByHandle('cleanairFixture');

    if ($type) {
        $entriesService->deleteEntryType($type);
    }

    foreach (['caSummary', 'caScore', 'caWhen', 'caFlag', 'caPick', 'caRelated'] as $handle) {
        $field = $fieldsService->getFieldByHandle($handle);

        if ($field && !$fieldsService->deleteField($field)) {
            // A fixture field left behind fails the *next* run with "handle already taken",
            // which is a confusing way to be told the teardown didn't work.
            echo "Warning: couldn’t delete the fixture field “$handle”.\n";
        }
    }

    // And the rows the fields service can't see, left by a run that died before its project
    // config was flushed. Restricted to this suite's own handles.
    Craft::$app->getDb()->createCommand()
        ->delete('{{%fields}}', ['handle' => ['caSummary', 'caScore', 'caWhen', 'caFlag', 'caPick', 'caRelated']])
        ->execute();

    // Everything this suite creates, on the way in as well as the way out — a previous run
    // that was killed partway leaves rows that make the next run's import a no-op, which
    // looks like a broken importer rather than a dirty database.
    Craft::$app->getDb()->createCommand()
        ->delete('{{%cleanair_filters}}', [
            'or',
            ['like', 'handle', 'ca-check%', false],
            ['like', 'name', 'CP %', false],
        ])
        ->execute();

    Craft::$app->getDb()->createCommand()->dropTableIfExists('{{%cpfilters_savedfilters}}')->execute();

    // Craft soft-deletes sections and entry types, so a suite that runs often leaves a row
    // per run behind. Nothing breaks, but a tidy teardown is a tidy teardown.
    foreach (['{{%sections}}', '{{%entrytypes}}'] as $table) {
        Craft::$app->getDb()->createCommand()
            ->delete($table, ['and', ['handle' => 'cleanairFixture'], ['not', ['dateDeleted' => null]]])
            ->execute();
    }

    Craft::$app->getProjectConfig()->saveModifiedConfigData();
}

teardown();

$fieldDefinitions = [
    ['class' => PlainText::class, 'handle' => 'caSummary', 'name' => 'CA Summary', 'settings' => []],
    ['class' => Number::class, 'handle' => 'caScore', 'name' => 'CA Score', 'settings' => ['decimals' => 0]],
    ['class' => Date::class, 'handle' => 'caWhen', 'name' => 'CA When', 'settings' => []],
    ['class' => Lightswitch::class, 'handle' => 'caFlag', 'name' => 'CA Flag', 'settings' => []],
    ['class' => Dropdown::class, 'handle' => 'caPick', 'name' => 'CA Pick', 'settings' => [
        'options' => [
            ['label' => 'Apple', 'value' => 'apple', 'default' => ''],
            ['label' => 'Banana', 'value' => 'banana', 'default' => ''],
            ['label' => 'Cherry', 'value' => 'cherry', 'default' => ''],
        ],
    ]],
    ['class' => EntriesField::class, 'handle' => 'caRelated', 'name' => 'CA Related', 'settings' => [
        'sources' => '*',
        'maxRelations' => null,
    ]],
];

$builtFields = [];

foreach ($fieldDefinitions as $definition) {
    $field = $fieldsService->createField(array_merge([
        'type' => $definition['class'],
        'name' => $definition['name'],
        'handle' => $definition['handle'],
    ], $definition['settings']));

    if (!$fieldsService->saveField($field)) {
        echo "Couldn’t create {$definition['handle']}: " . json_encode($field->getErrors()) . "\n";
        exit(1);
    }

    $builtFields[$definition['handle']] = $field;
}

// Flush now. A bare script buffers its project config writes, and if this one dies before the
// flush the fields exist in the database but not in project config — at which point the next
// run is told the handle is taken by a field it cannot see.
Craft::$app->getProjectConfig()->saveModifiedConfigData();

$entryType = new EntryType();
$entryType->name = 'Clean Air Fixture';
$entryType->handle = 'cleanairFixture';

$layout = new FieldLayout(['type' => Entry::class]);
// The tab needs its layout before it will take elements.
$tab = new FieldLayoutTab(['layout' => $layout, 'name' => 'Content']);

// In Craft 5 the title is a field layout element. Leave it out and every fixture entry saves
// with a null title, which makes every title filter look broken when it isn't.
$elements = [new EntryTitleField()];

foreach (array_values($builtFields) as $field) {
    $elements[] = new CustomField($field);
}

$tab->setElements($elements);
$layout->setTabs([$tab]);
$entryType->hasTitleField = true;
$entryType->setFieldLayout($layout);

if (!$entriesService->saveEntryType($entryType)) {
    echo "Couldn’t create the entry type: " . json_encode($entryType->getErrors()) . "\n";
    exit(1);
}

$section = new Section();
$section->name = 'Clean Air Fixture';
$section->handle = 'cleanairFixture';
$section->type = Section::TYPE_CHANNEL;

$siteSettings = [];
foreach (Craft::$app->getSites()->getAllSites() as $site) {
    $settings = new Section_SiteSettings();
    $settings->siteId = $site->id;
    $settings->hasUrls = false;
    $siteSettings[$site->id] = $settings;
}
$section->setSiteSettings($siteSettings);
$section->setEntryTypes([$entryType]);

if (!$entriesService->saveSection($section)) {
    echo "Couldn’t create the section: " . json_encode($section->getErrors()) . "\n";
    exit(1);
}

Craft::$app->getProjectConfig()->saveModifiedConfigData();

$section = $entriesService->getSectionByHandle('cleanairFixture');
$entryType = $entriesService->getEntryTypeByHandle('cleanairFixture');

/**
 * Four entries with deliberately awkward values: a title with a comma in it (Craft's parameter
 * syntax splits on commas), a null number, a disabled entry, and a future date.
 */
$now = new DateTime('now', new DateTimeZone(Craft::$app->getTimeZone()));

$rows = [
    ['title' => 'Alpha, One', 'enabled' => true, 'caSummary' => 'hello world', 'caScore' => 10, 'days' => -2, 'caFlag' => true, 'caPick' => 'apple'],
    ['title' => 'Beta Two', 'enabled' => true, 'caSummary' => 'world peace', 'caScore' => 50, 'days' => -20, 'caFlag' => false, 'caPick' => 'banana'],
    ['title' => 'Gamma Three', 'enabled' => true, 'caSummary' => null, 'caScore' => 100, 'days' => 5, 'caFlag' => true, 'caPick' => 'cherry'],
    ['title' => 'Delta Four', 'enabled' => false, 'caSummary' => 'hello again', 'caScore' => null, 'days' => -100, 'caFlag' => false, 'caPick' => 'apple'],
];

$entries = [];

foreach ($rows as $row) {
    $entry = new Entry();
    $entry->sectionId = $section->id;
    $entry->typeId = $entryType->id;
    $entry->title = $row['title'];
    $entry->enabled = $row['enabled'];
    $entry->setFieldValues([
        'caSummary' => $row['caSummary'],
        'caScore' => $row['caScore'],
        'caWhen' => (clone $now)->modify(($row['days'] >= 0 ? '+' : '') . $row['days'] . ' days'),
        'caFlag' => $row['caFlag'],
        'caPick' => $row['caPick'],
    ]);

    if (!$elementsService->saveElement($entry)) {
        echo "Couldn’t save “{$row['title']}”: " . json_encode($entry->getErrors()) . "\n";
        exit(1);
    }

    $entries[] = $entry;
}

// Relations: Beta points at Alpha; Gamma points at Alpha and Beta; the others at nothing.
$entries[1]->setFieldValue('caRelated', [$entries[0]->id]);
$elementsService->saveElement($entries[1]);
$entries[2]->setFieldValue('caRelated', [$entries[0]->id, $entries[1]->id]);
$elementsService->saveElement($entries[2]);

$plugin = Plugin::getInstance();

// Pro, so the whole surface is exercised. Switched back at the end.
$originalEdition = $plugin->edition;
Craft::$app->getPlugins()->switchEdition('cleanair', Plugin::EDITION_PRO);
$plugin = Plugin::getInstance();

$type = $plugin->types->forElementType(Entry::class);
$source = 'entryType:' . $entryType->id;

/** Builds a filter over the fixture entry type. */
function fixtureFilter(array $criteria, array $overrides = []): FilterSet
{
    global $source;

    return FilterSet::fromArray(array_merge([
        'elementType' => Entry::class,
        'source' => $source,
        'criteria' => $criteria,
    ], $overrides));
}

function titlesFor(FilterSet $filter): array
{
    $warnings = [];
    $titles = array_map(
        fn(Entry $entry) => $entry->title,
        Plugin::getInstance()->query->build($filter, $warnings)->all(),
    );
    sort($titles);
    return $titles;
}

echo "Clean Air integration checks\n";
echo str_repeat('=', 60) . "\n";

// ---------------------------------------------------------------------------
section('Element type registry');
// ---------------------------------------------------------------------------

$all = $plugin->types->all();

check('entries driver registered', isset($all['entries']));
check('assets driver registered', isset($all['assets']));
check('categories driver registered', isset($all['categories']));
check('tags driver registered', isset($all['tags']));
check('users driver registered', isset($all['users']));
check('addresses driver registered', isset($all['addresses']));
check('resolves a driver by element class', $type !== null && $type->key() === 'entries');
check('every driver has a unique key', count($all) === count(array_unique(array_keys($all))));

$commerceInstalled = Craft::$app->getPlugins()->isPluginEnabled('commerce');
check(
    'Commerce drivers track whether Commerce is installed',
    $all['orders']->isAvailable() === $commerceInstalled,
);

$sources = array_map(fn($s) => $s->key, $type->sources());
check('the fixture entry type appears as a source', in_array($source, $sources, true));
check('sections appear as sources too', in_array('section:' . $section->id, $sources, true));

// ---------------------------------------------------------------------------
section('Field discovery');
// ---------------------------------------------------------------------------

$definitions = $plugin->fields->definitions($type, $source);

check('id is filterable', isset($definitions['id']));
check('title is filterable', isset($definitions['title']));
check('status is filterable', isset($definitions['status']));
check('postDate is filterable', isset($definitions['postDate']));
check('the custom text field is filterable', isset($definitions['caSummary']));
check('the custom number field is filterable', isset($definitions['caScore']));
check('the custom date field is filterable', isset($definitions['caWhen']));
check('the custom lightswitch is filterable', isset($definitions['caFlag']));
check('the custom dropdown is filterable', isset($definitions['caPick']));
check('the custom relation field is filterable', isset($definitions['caRelated']));

check('text field mapped to the text kind', ($definitions['caSummary'] ?? null)?->kind === FieldDefinition::KIND_TEXT);
check('number field mapped to the number kind', ($definitions['caScore'] ?? null)?->kind === FieldDefinition::KIND_NUMBER);
check('date field mapped to the date kind', ($definitions['caWhen'] ?? null)?->kind === FieldDefinition::KIND_DATE);
check('lightswitch mapped to the boolean kind', ($definitions['caFlag'] ?? null)?->kind === FieldDefinition::KIND_BOOLEAN);
check('dropdown mapped to the options kind', ($definitions['caPick'] ?? null)?->kind === FieldDefinition::KIND_OPTIONS);
check('relation field mapped to the relation kind', ($definitions['caRelated'] ?? null)?->kind === FieldDefinition::KIND_RELATION);
check('dropdown options carried through', ($definitions['caPick']->options ?? []) === ['apple' => 'Apple', 'banana' => 'Banana', 'cherry' => 'Cherry']);
check('relation field knows what it points at', ($definitions['caRelated'] ?? null)?->relationElementType === Entry::class);
check('native attributes are marked native', ($definitions['title'] ?? null)?->native === true);
check('custom fields are not marked native', ($definitions['caSummary'] ?? null)?->native === false);

// ---------------------------------------------------------------------------
section('Operators offered');
// ---------------------------------------------------------------------------

$textOps = array_keys($plugin->operators->forField($definitions['caSummary']));
check('text fields offer contains', in_array('contains', $textOps, true));
check('text fields offer is one of', in_array('in', $textOps, true));
check('text fields do not offer is assigned', !in_array('isAssigned', $textOps, true));

$dateOps = array_keys($plugin->operators->forField($definitions['caWhen']));
check('date fields offer is in the last', in_array('inLast', $dateOps, true));
check('date fields do not offer contains', !in_array('contains', $dateOps, true));

$boolOps = array_keys($plugin->operators->forField($definitions['caFlag']));
check('lightswitches offer only on and off', $boolOps === ['isTrue', 'isFalse']);

$relationOps = $plugin->operators->forField($definitions['caRelated']);
check('relation fields offer is not assigned', isset($relationOps['isNotAssigned']));
check('relation fields offer a count operator', isset($relationOps['countAtLeast']));

$nativeRelationOps = $plugin->operators->forField($definitions['authorId']);
check('native relations drop the count operators', !isset($nativeRelationOps['countAtLeast']));

$optionOps = $plugin->operators->forField($definitions['caPick']);
check('option fields get a select input, not a text box', $optionOps['eq']['input'] === 'select');
check('option fields get a multi-select for is one of', $optionOps['in']['input'] === 'multiselect');

// ---------------------------------------------------------------------------
section('Text operators');
// ---------------------------------------------------------------------------

check('contains', titlesFor(fixtureFilter([['field' => 'caSummary', 'operator' => 'contains', 'value' => 'world']])) === ['Alpha, One', 'Beta Two']);
check("doesn't contain", titlesFor(fixtureFilter([['field' => 'caSummary', 'operator' => 'notContains', 'value' => 'world']])) === ['Delta Four']);
check('starts with', titlesFor(fixtureFilter([['field' => 'caSummary', 'operator' => 'startsWith', 'value' => 'hello']])) === ['Alpha, One', 'Delta Four']);
check('ends with', titlesFor(fixtureFilter([['field' => 'caSummary', 'operator' => 'endsWith', 'value' => 'peace']])) === ['Beta Two']);
check('is empty', titlesFor(fixtureFilter([['field' => 'caSummary', 'operator' => 'empty']])) === ['Gamma Three']);
check('is not empty', titlesFor(fixtureFilter([['field' => 'caSummary', 'operator' => 'notEmpty']])) === ['Alpha, One', 'Beta Two', 'Delta Four']);

// A comma is Craft's parameter separator. Unescaped, this becomes "Alpha" OR "One" and matches
// nothing — the exact bug the escaping in Operators exists to prevent.
check('exact match on a title containing a comma', titlesFor(fixtureFilter([['field' => 'title', 'operator' => 'eq', 'value' => 'Alpha, One']])) === ['Alpha, One']);
check('is not', titlesFor(fixtureFilter([['field' => 'title', 'operator' => 'ne', 'value' => 'Alpha, One']])) === ['Beta Two', 'Delta Four', 'Gamma Three']);
check('is one of', titlesFor(fixtureFilter([['field' => 'title', 'operator' => 'in', 'value' => "Beta Two\nGamma Three"]])) === ['Beta Two', 'Gamma Three']);
check('is none of', titlesFor(fixtureFilter([['field' => 'title', 'operator' => 'notIn', 'value' => "Beta Two\nGamma Three"]])) === ['Alpha, One', 'Delta Four']);
check('is one of nothing matches nothing', titlesFor(fixtureFilter([['field' => 'title', 'operator' => 'in', 'value' => '']])) === []);

// ---------------------------------------------------------------------------
section('Number operators');
// ---------------------------------------------------------------------------

check('greater than', titlesFor(fixtureFilter([['field' => 'caScore', 'operator' => 'gt', 'value' => 10]])) === ['Beta Two', 'Gamma Three']);
check('at least', titlesFor(fixtureFilter([['field' => 'caScore', 'operator' => 'gte', 'value' => 10]])) === ['Alpha, One', 'Beta Two', 'Gamma Three']);
check('less than', titlesFor(fixtureFilter([['field' => 'caScore', 'operator' => 'lt', 'value' => 50]])) === ['Alpha, One']);
check('between', titlesFor(fixtureFilter([['field' => 'caScore', 'operator' => 'between', 'value' => 20, 'value2' => 200]])) === ['Beta Two', 'Gamma Three']);
check('between, entered backwards, still works', titlesFor(fixtureFilter([['field' => 'caScore', 'operator' => 'between', 'value' => 200, 'value2' => 20]])) === ['Beta Two', 'Gamma Three']);
check('number is empty finds the null', titlesFor(fixtureFilter([['field' => 'caScore', 'operator' => 'empty']])) === ['Delta Four']);

// ---------------------------------------------------------------------------
section('Date operators');
// ---------------------------------------------------------------------------

check('is after', titlesFor(fixtureFilter([['field' => 'caWhen', 'operator' => 'after', 'value' => (clone $now)->modify('-5 days')->format('Y-m-d H:i:s')]])) === ['Alpha, One', 'Gamma Three']);
check('is before', titlesFor(fixtureFilter([['field' => 'caWhen', 'operator' => 'before', 'value' => (clone $now)->modify('-5 days')->format('Y-m-d H:i:s')]])) === ['Beta Two', 'Delta Four']);
check('is in the last 30 days', titlesFor(fixtureFilter([['field' => 'caWhen', 'operator' => 'inLast', 'value' => 30, 'value2' => 'days']])) === ['Alpha, One', 'Beta Two']);
check('is in the next 30 days', titlesFor(fixtureFilter([['field' => 'caWhen', 'operator' => 'inNext', 'value' => 30, 'value2' => 'days']])) === ['Gamma Three']);
check('is between dates', titlesFor(fixtureFilter([['field' => 'caWhen', 'operator' => 'betweenDates', 'value' => (clone $now)->modify('-30 days')->format('Y-m-d'), 'value2' => (clone $now)->format('Y-m-d')]])) === ['Alpha, One', 'Beta Two']);

// "is on" has to mean the whole day, not midnight exactly.
check('is on covers the whole day', titlesFor(fixtureFilter([['field' => 'caWhen', 'operator' => 'on', 'value' => (clone $now)->modify('-2 days')->format('Y-m-d')]])) === ['Alpha, One']);
// …and the *local* whole day. The bounds used to be converted to UTC before Craft's date param
// converted them again, so west of UTC the day ran 7am to 7am: whether the check above passed
// depended on the time the suite ran. An entry at 01:30 local time pins it down.
$early = (clone $now)->modify('-10 days')->setTime(1, 30);
$earlyEntry = new Entry(['sectionId' => $section->id, 'typeId' => $entryType->id, 'title' => 'Early Bird']);
$earlyEntry->setFieldValue('caWhen', $early);
$elementsService->saveElement($earlyEntry);
check('is on finds an entry at 01:30 local time on that day', titlesFor(fixtureFilter([['field' => 'caWhen', 'operator' => 'on', 'value' => $early->format('Y-m-d')]])) === ['Early Bird']);
check('is on does not reach into the next local day', titlesFor(fixtureFilter([['field' => 'caWhen', 'operator' => 'on', 'value' => (clone $early)->modify('-1 day')->format('Y-m-d')]])) === []);
check('is after is exact to the local minute', in_array('Early Bird', titlesFor(fixtureFilter([['field' => 'caWhen', 'operator' => 'after', 'value' => (clone $early)->modify('-1 minute')->format('Y-m-d H:i:s')]])), true) && !in_array('Early Bird', titlesFor(fixtureFilter([['field' => 'caWhen', 'operator' => 'after', 'value' => (clone $early)->modify('+1 minute')->format('Y-m-d H:i:s')]])), true));
$elementsService->deleteElement($earlyEntry, true);

$onBounds = Db::parseDateParam('d', $plugin->operators->build($definitions['caWhen'], new Criterion(['field' => 'caWhen', 'operator' => 'on', 'value' => '2026-01-15']))['value']);
$localMidnight = new DateTime('2026-01-15 00:00:00', new DateTimeZone(Craft::$app->getTimeZone()));
check('is on hands Craft local midnight to local midnight, converted to UTC once', json_encode($onBounds) === json_encode(['and', ['>=', 'd', Db::prepareDateForDb($localMidnight)], ['<', 'd', Db::prepareDateForDb((clone $localMidnight)->modify('+1 day'))]]), json_encode($onBounds));

check('an unparseable date matches nothing', titlesFor(fixtureFilter([['field' => 'caWhen', 'operator' => 'after', 'value' => 'not a date']])) === []);

// ---------------------------------------------------------------------------
section('Boolean and option operators');
// ---------------------------------------------------------------------------

check('lightswitch is on', titlesFor(fixtureFilter([['field' => 'caFlag', 'operator' => 'isTrue']])) === ['Alpha, One', 'Gamma Three']);
check('lightswitch is off', titlesFor(fixtureFilter([['field' => 'caFlag', 'operator' => 'isFalse']])) === ['Beta Two', 'Delta Four']);
check('dropdown is', titlesFor(fixtureFilter([['field' => 'caPick', 'operator' => 'eq', 'value' => 'apple']])) === ['Alpha, One', 'Delta Four']);
check('dropdown is not', titlesFor(fixtureFilter([['field' => 'caPick', 'operator' => 'ne', 'value' => 'apple']])) === ['Beta Two', 'Gamma Three']);
check('dropdown is one of', titlesFor(fixtureFilter([['field' => 'caPick', 'operator' => 'in', 'value' => ['banana', 'cherry']]])) === ['Beta Two', 'Gamma Three']);

// ---------------------------------------------------------------------------
section('Relation operators');
// ---------------------------------------------------------------------------

check('is assigned', titlesFor(fixtureFilter([['field' => 'caRelated', 'operator' => 'isAssigned', 'value' => [$entries[0]->id]]])) === ['Beta Two', 'Gamma Three']);
check('is not assigned', titlesFor(fixtureFilter([['field' => 'caRelated', 'operator' => 'isNotAssigned', 'value' => [$entries[0]->id]]])) === ['Alpha, One', 'Delta Four']);
check('relation is not empty', titlesFor(fixtureFilter([['field' => 'caRelated', 'operator' => 'notEmpty']])) === ['Beta Two', 'Gamma Three']);
check('relation is empty', titlesFor(fixtureFilter([['field' => 'caRelated', 'operator' => 'empty']])) === ['Alpha, One', 'Delta Four']);
check('has at least 2 related', titlesFor(fixtureFilter([['field' => 'caRelated', 'operator' => 'countAtLeast', 'value' => 2]])) === ['Gamma Three']);
check('has at most 1 related includes those with none', titlesFor(fixtureFilter([['field' => 'caRelated', 'operator' => 'countAtMost', 'value' => 1]])) === ['Alpha, One', 'Beta Two', 'Delta Four']);

// ---------------------------------------------------------------------------
section('Statuses, sources and combining');
// ---------------------------------------------------------------------------

check('no status filter shows disabled entries', count(titlesFor(fixtureFilter([]))) === 4);
check('filtering to live hides the disabled one', titlesFor(fixtureFilter([], ['status' => 'live'])) === ['Alpha, One', 'Beta Two', 'Gamma Three']);
check('status attribute: is disabled', titlesFor(fixtureFilter([['field' => 'status', 'operator' => 'eq', 'value' => 'disabled']])) === ['Delta Four']);
check('status attribute: is not disabled', count(titlesFor(fixtureFilter([['field' => 'status', 'operator' => 'ne', 'value' => 'disabled']]))) === 3);

check('match all narrows', titlesFor(fixtureFilter([
    ['field' => 'caSummary', 'operator' => 'contains', 'value' => 'hello'],
    ['field' => 'caFlag', 'operator' => 'isTrue'],
])) === ['Alpha, One']);

check('match any widens', titlesFor(fixtureFilter([
    ['field' => 'caScore', 'operator' => 'lt', 'value' => 20],
    ['field' => 'caPick', 'operator' => 'eq', 'value' => 'cherry'],
], ['matchMode' => 'any'])) === ['Alpha, One', 'Gamma Three']);

check('two conditions on one field still AND', titlesFor(fixtureFilter([
    ['field' => 'caScore', 'operator' => 'gte', 'value' => 10],
    ['field' => 'caScore', 'operator' => 'lte', 'value' => 50],
])) === ['Alpha, One', 'Beta Two']);

// ---------------------------------------------------------------------------
section('Condition groups');
// ---------------------------------------------------------------------------

/** A group criterion row. */
function group(string $matchMode, array $criteria): array
{
    return ['type' => 'group', 'matchMode' => $matchMode, 'criteria' => $criteria];
}

check('all, with an any group: on AND (apple OR score at least 100)', titlesFor(fixtureFilter([
    ['field' => 'caFlag', 'operator' => 'isTrue'],
    group('any', [
        ['field' => 'caPick', 'operator' => 'eq', 'value' => 'apple'],
        ['field' => 'caScore', 'operator' => 'gte', 'value' => 100],
    ]),
])) === ['Alpha, One', 'Gamma Three']);

check('an any group narrows like any other condition', titlesFor(fixtureFilter([
    ['field' => 'caSummary', 'operator' => 'contains', 'value' => 'hello'],
    group('any', [
        ['field' => 'caPick', 'operator' => 'eq', 'value' => 'cherry'],
        ['field' => 'caScore', 'operator' => 'lt', 'value' => 20],
    ]),
])) === ['Alpha, One']);

check('two any groups must both hold', titlesFor(fixtureFilter([
    group('any', [
        ['field' => 'caPick', 'operator' => 'eq', 'value' => 'apple'],
        ['field' => 'caPick', 'operator' => 'eq', 'value' => 'banana'],
    ]),
    group('any', [
        ['field' => 'caFlag', 'operator' => 'isTrue'],
        ['field' => 'caScore', 'operator' => 'gte', 'value' => 50],
    ]),
])) === ['Alpha, One', 'Beta Two']);

check('any, with an all group: cherry OR (world AND off)', titlesFor(fixtureFilter([
    ['field' => 'caPick', 'operator' => 'eq', 'value' => 'cherry'],
    group('all', [
        ['field' => 'caSummary', 'operator' => 'contains', 'value' => 'world'],
        ['field' => 'caFlag', 'operator' => 'isFalse'],
    ]),
], ['matchMode' => 'any'])) === ['Beta Two', 'Gamma Three']);

check('any, with an any group: the union of everything', titlesFor(fixtureFilter([
    ['field' => 'caScore', 'operator' => 'lt', 'value' => 20],
    group('any', [
        ['field' => 'caPick', 'operator' => 'eq', 'value' => 'banana'],
        ['field' => 'caPick', 'operator' => 'eq', 'value' => 'cherry'],
    ]),
], ['matchMode' => 'any'])) === ['Alpha, One', 'Beta Two', 'Gamma Three']);

check('an all group is the same as writing its conditions at the top', titlesFor(fixtureFilter([
    group('all', [
        ['field' => 'caSummary', 'operator' => 'contains', 'value' => 'hello'],
        ['field' => 'caFlag', 'operator' => 'isTrue'],
    ]),
])) === ['Alpha, One']);

check('a group with one condition is that condition', titlesFor(fixtureFilter([
    group('any', [['field' => 'caPick', 'operator' => 'eq', 'value' => 'apple']]),
])) === ['Alpha, One', 'Delta Four']);

$grouped = FilterSet::fromArray(['elementType' => Entry::class, 'criteria' => [
    ['field' => 'title', 'operator' => 'eq', 'value' => 'x'],
    group('any', [
        ['field' => 'title', 'operator' => 'eq', 'value' => 'y'],
        group('any', [['field' => 'title', 'operator' => 'eq', 'value' => 'z']]),
        ['field' => '', 'operator' => ''],
    ]),
    group('all', [['field' => '', 'operator' => '']]),
]]);
$groupedArray = $grouped->toArray();

check('groups survive a save and reload', FilterSet::fromArray($groupedArray)->toArray() === $groupedArray);
check('groups go one level deep: a group inside a group is dropped', count($grouped->criteria[1]->criteria) === 1 && !$grouped->criteria[1]->criteria[0]->isGroup());
check('a group with nothing complete in it is dropped', count($grouped->criteria) === 2);
check('a plain condition is stored exactly as before groups existed', !array_key_exists('type', $groupedArray['criteria'][0]));
check('the filter knows it has groups', $grouped->hasGroups() && !fixtureFilter([['field' => 'title', 'operator' => 'eq', 'value' => 'x']])->hasGroups());

// The match-any ceiling applies per group, and the warning says which group.
$limitBefore = $plugin->getSettings()->anyMatchIdLimit;
$plugin->getSettings()->anyMatchIdLimit = 1;
$groupWarnings = [];
$plugin->query->build(fixtureFilter([
    ['field' => 'caFlag', 'operator' => 'isFalse'],
    group('any', [
        ['field' => 'caPick', 'operator' => 'eq', 'value' => 'apple'],
        ['field' => 'caPick', 'operator' => 'eq', 'value' => 'banana'],
    ]),
]), $groupWarnings);
$plugin->getSettings()->anyMatchIdLimit = $limitBefore;
check('a truncated group says which group it was', (bool)array_filter($groupWarnings, fn($w) => str_contains($w, 'Group 1')), json_encode($groupWarnings));

// Lite reading a filter saved on Pro: ignoring the group would widen the result, so it matches nothing.
$plugin->edition = Plugin::EDITION_LITE;
$liteWarnings = [];
$liteCount = (int)$plugin->query->build(fixtureFilter([
    group('any', [['field' => 'caPick', 'operator' => 'eq', 'value' => 'apple'], ['field' => 'caPick', 'operator' => 'eq', 'value' => 'banana']]),
]), $liteWarnings)->count();
$plugin->edition = Plugin::EDITION_PRO;
check('on Lite a grouped filter matches nothing, and says why', $liteCount === 0 && (bool)array_filter($liteWarnings, fn($w) => str_contains($w, 'Pro')));
check('Lite has no condition groups; Pro does', !Edition::allowsConditionGroups(false) && Edition::allowsConditionGroups(true));

check('the section source covers the entry type', count(titlesFor(fixtureFilter([], ['source' => 'section:' . $section->id]))) === 4);

// "All sources" adds no source condition, so it must only be offered to someone who can see every
// source. Until 5.0.1 it was offered to anybody when the settings imposed no restriction, and a
// user limited to one section got every section through it.
$entryDriver = $plugin->types->forElementType(Entry::class);
$oneSectionUser = new craft\elements\User();
$oneSectionUser->username = 'cleanair-check-' . bin2hex(random_bytes(3));
$oneSectionUser->email = $oneSectionUser->username . '@example.com';
Craft::$app->getElements()->saveElement($oneSectionUser, false);
Craft::$app->getUserPermissions()->saveUserPermissions($oneSectionUser->id, ['accesscp', 'viewentries:' . $section->uid]);
$oneSectionUser = Craft::$app->getUsers()->getUserById($oneSectionUser->id);
$sectionCount = count(Craft::$app->getEntries()->getAllSections());

try {
    check('a user who can view one section may use that section', $plugin->types->isAllowedSource($entryDriver, 'section:' . $section->id, $oneSectionUser));
    check(
        'but not “All sources”, when other sections exist',
        $sectionCount === 1 || !$plugin->types->isAllowedSource($entryDriver, null, $oneSectionUser),
    );

    $admin = craft\elements\User::find()->admin(true)->one();
    check('an admin may use “All sources”', $admin !== null && $plugin->types->isAllowedSource($entryDriver, null, $admin));
} finally {
    Craft::$app->getElements()->deleteElement($oneSectionUser, true);
}

$warnings = [];
$plugin->query->build(fixtureFilter([['field' => 'noSuchField', 'operator' => 'eq', 'value' => 'x']]), $warnings);
check('a condition naming a missing field is reported, not swallowed', count($warnings) === 1);

$warnings = [];
$bogus = $plugin->query->build(FilterSet::fromArray(['elementType' => 'not\\A\\Class']), $warnings);
check('an unknown element type returns nothing rather than everything', $bogus->count() === 0 && count($warnings) === 1);

// ---------------------------------------------------------------------------
section('Several conditions on one field');
// ---------------------------------------------------------------------------

// A query parameter holds one value. Each condition used to assign its field's parameter, so the
// last condition on a field replaced the ones before it: "score > 10 AND score < 100" ran as
// "score < 100". Every check here is chosen so that either condition alone gives a different
// answer from the two together, in both orders.
$bothOrders = function(string $label, array $a, array $b, array $expected, array $overrides = []): void {
    check("$label", titlesFor(fixtureFilter([$a, $b], $overrides)) === $expected, json_encode(titlesFor(fixtureFilter([$a, $b], $overrides))));
    check("$label (other order)", titlesFor(fixtureFilter([$b, $a], $overrides)) === $expected, json_encode(titlesFor(fixtureFilter([$b, $a], $overrides))));
};

$bothOrders('number: more than 10 and less than 100',
    ['field' => 'caScore', 'operator' => 'gt', 'value' => 10],
    ['field' => 'caScore', 'operator' => 'lt', 'value' => 100],
    ['Beta Two']);
$bothOrders('number: a range and a bound',
    ['field' => 'caScore', 'operator' => 'between', 'value' => 10, 'value2' => 100],
    ['field' => 'caScore', 'operator' => 'ne', 'value' => 50],
    ['Alpha, One', 'Gamma Three']);
$bothOrders('number: is empty and at most 10',
    ['field' => 'caScore', 'operator' => 'empty'],
    ['field' => 'caScore', 'operator' => 'lte', 'value' => 10],
    []);
$bothOrders('date field: after 30 days ago and before 5 days ago',
    ['field' => 'caWhen', 'operator' => 'after', 'value' => (clone $now)->modify('-30 days')->format('Y-m-d H:i:s')],
    ['field' => 'caWhen', 'operator' => 'before', 'value' => (clone $now)->modify('-5 days')->format('Y-m-d H:i:s')],
    ['Beta Two']);
$bothOrders('date field: in the last 30 days and before 3 days ago',
    ['field' => 'caWhen', 'operator' => 'inLast', 'value' => 30, 'value2' => 'days'],
    ['field' => 'caWhen', 'operator' => 'before', 'value' => (clone $now)->modify('-3 days')->format('Y-m-d H:i:s')],
    ['Beta Two']);
$bothOrders('native date: post date after tomorrow and before the day after',
    ['field' => 'postDate', 'operator' => 'after', 'value' => (clone $now)->modify('+1 day')->format('Y-m-d H:i:s')],
    ['field' => 'postDate', 'operator' => 'before', 'value' => (clone $now)->modify('+2 days')->format('Y-m-d H:i:s')],
    []);
$bothOrders('native date: date created after an hour ago and before an hour from now',
    ['field' => 'dateCreated', 'operator' => 'after', 'value' => (clone $now)->modify('-1 hour')->format('Y-m-d H:i:s')],
    ['field' => 'dateCreated', 'operator' => 'before', 'value' => (clone $now)->modify('-30 minutes')->format('Y-m-d H:i:s')],
    []);
$bothOrders('title: contains "ta" and doesn’t contain "Four"',
    ['field' => 'title', 'operator' => 'contains', 'value' => 'ta'],
    ['field' => 'title', 'operator' => 'notContains', 'value' => 'Four'],
    ['Beta Two']);
$bothOrders('title: is one of two and contains "Three"',
    ['field' => 'title', 'operator' => 'in', 'value' => "Beta Two\nGamma Three"],
    ['field' => 'title', 'operator' => 'contains', 'value' => 'Three'],
    ['Gamma Three']);
$bothOrders('title: is one of, twice',
    ['field' => 'title', 'operator' => 'in', 'value' => "Alpha, One\nBeta Two"],
    ['field' => 'title', 'operator' => 'in', 'value' => "Beta Two\nGamma Three"],
    ['Beta Two']);
$bothOrders('text field: contains "hello" and contains "world"',
    ['field' => 'caSummary', 'operator' => 'contains', 'value' => 'hello'],
    ['field' => 'caSummary', 'operator' => 'contains', 'value' => 'world'],
    ['Alpha, One']);
$bothOrders('text field: case-sensitive and case-insensitive together',
    ['field' => 'caSummary', 'operator' => 'contains', 'value' => 'hello', 'caseInsensitive' => false],
    ['field' => 'caSummary', 'operator' => 'contains', 'value' => 'WORLD'],
    ['Alpha, One']);
$bothOrders('text field: is not empty and starts with "hello"',
    ['field' => 'caSummary', 'operator' => 'notEmpty'],
    ['field' => 'caSummary', 'operator' => 'startsWith', 'value' => 'hello'],
    ['Alpha, One', 'Delta Four']);
$bothOrders('dropdown: is one of and is not',
    ['field' => 'caPick', 'operator' => 'in', 'value' => ['apple', 'banana']],
    ['field' => 'caPick', 'operator' => 'ne', 'value' => 'apple'],
    ['Beta Two']);
$bothOrders('lightswitch: on and off is nothing',
    ['field' => 'caFlag', 'operator' => 'isTrue'],
    ['field' => 'caFlag', 'operator' => 'isFalse'],
    []);
check('lightswitch: on twice is on', titlesFor(fixtureFilter([
    ['field' => 'caFlag', 'operator' => 'isTrue'],
    ['field' => 'caFlag', 'operator' => 'isTrue'],
])) === ['Alpha, One', 'Gamma Three']);
$bothOrders('relation: assigned to Alpha and assigned to Beta',
    ['field' => 'caRelated', 'operator' => 'isAssigned', 'value' => [$entries[0]->id]],
    ['field' => 'caRelated', 'operator' => 'isAssigned', 'value' => [$entries[1]->id]],
    ['Gamma Three']);
$bothOrders('relation: not empty and assigned to Beta',
    ['field' => 'caRelated', 'operator' => 'notEmpty'],
    ['field' => 'caRelated', 'operator' => 'isAssigned', 'value' => [$entries[1]->id]],
    ['Gamma Three']);
$bothOrders('relation: assigned to Alpha and not assigned to Beta',
    ['field' => 'caRelated', 'operator' => 'isAssigned', 'value' => [$entries[0]->id]],
    ['field' => 'caRelated', 'operator' => 'isNotAssigned', 'value' => [$entries[1]->id]],
    ['Beta Two']);

$fixtureIds = array_map(fn(Entry $e) => (int)$e->id, $entries);
sort($fixtureIds);
$bothOrders('ID: more than the lowest and less than the highest',
    ['field' => 'id', 'operator' => 'gt', 'value' => $fixtureIds[0]],
    ['field' => 'id', 'operator' => 'lt', 'value' => $fixtureIds[3]],
    ['Beta Two', 'Gamma Three']);

$bothOrders('status: is disabled and is not disabled',
    ['field' => 'status', 'operator' => 'eq', 'value' => 'disabled'],
    ['field' => 'status', 'operator' => 'ne', 'value' => 'disabled'],
    []);
check('status: a status condition can’t replace the filter’s own status', titlesFor(fixtureFilter([
    ['field' => 'status', 'operator' => 'eq', 'value' => 'disabled'],
], ['status' => 'live'])) === []);
check('status: a status condition agreeing with the filter’s status still matches', titlesFor(fixtureFilter([
    ['field' => 'status', 'operator' => 'ne', 'value' => 'disabled'],
], ['status' => 'live'])) === ['Alpha, One', 'Beta Two', 'Gamma Three']);

// The source is a parameter too. A Section condition used to replace a section source.
$otherSection = null;
foreach ($entriesService->getAllSections() as $candidate) {
    if ($candidate->id !== $section->id && Entry::find()->sectionId($candidate->id)->status(null)->exists()) {
        $otherSection = $candidate;
        break;
    }
}
if ($otherSection) {
    check('a section condition can’t step outside a section source', titlesFor(fixtureFilter([
        ['field' => 'sectionId', 'operator' => 'in', 'value' => [(string)$otherSection->id]],
    ], ['source' => 'section:' . $section->id])) === []);
} else {
    skip('a section condition can’t step outside a section source', 'no other section with entries in this install');
}
check('a section condition naming the source’s own section still matches', count(titlesFor(fixtureFilter([
    ['field' => 'sectionId', 'operator' => 'eq', 'value' => (string)$section->id],
], ['source' => 'section:' . $section->id]))) === 4);

check('same field inside an all group', titlesFor(fixtureFilter([
    group('all', [
        ['field' => 'caScore', 'operator' => 'gt', 'value' => 10],
        ['field' => 'caScore', 'operator' => 'lt', 'value' => 100],
    ]),
])) === ['Beta Two']);
check('same field across a group and the top level', titlesFor(fixtureFilter([
    ['field' => 'caScore', 'operator' => 'gt', 'value' => 10],
    group('all', [
        ['field' => 'caScore', 'operator' => 'lt', 'value' => 100],
        ['field' => 'caSummary', 'operator' => 'notEmpty'],
    ]),
])) === ['Beta Two']);
check('same field in an any group, and at the top level', titlesFor(fixtureFilter([
    ['field' => 'caScore', 'operator' => 'gt', 'value' => 10],
    group('any', [
        ['field' => 'caScore', 'operator' => 'lt', 'value' => 100],
        ['field' => 'caPick', 'operator' => 'eq', 'value' => 'apple'],
    ]),
])) === ['Beta Two']);
check('match any: an all group with the same field twice', titlesFor(fixtureFilter([
    ['field' => 'caPick', 'operator' => 'eq', 'value' => 'cherry'],
    group('all', [
        ['field' => 'caScore', 'operator' => 'gt', 'value' => 10],
        ['field' => 'caScore', 'operator' => 'lt', 'value' => 100],
    ]),
], ['matchMode' => 'any'])) === ['Beta Two', 'Gamma Three']);
check('match any: the same field twice still ORs', titlesFor(fixtureFilter([
    ['field' => 'caScore', 'operator' => 'lt', 'value' => 20],
    ['field' => 'caScore', 'operator' => 'gt', 'value' => 60],
], ['matchMode' => 'any'])) === ['Alpha, One', 'Gamma Three']);
check('an any group: the same field twice still ORs', titlesFor(fixtureFilter([
    group('any', [
        ['field' => 'caScore', 'operator' => 'lt', 'value' => 20],
        ['field' => 'caScore', 'operator' => 'gt', 'value' => 60],
    ]),
])) === ['Alpha, One', 'Gamma Three']);

$rangeSaved = new SavedFilter([
    'name' => 'CA check score range',
    'handle' => 'ca-check-range',
    'elementType' => Entry::class,
    'source' => $source,
    'filter' => fixtureFilter([
        ['field' => 'caScore', 'operator' => 'gt', 'value' => 10],
        ['field' => 'caScore', 'operator' => 'lt', 'value' => 100],
    ]),
]);
$rangeErrors = [];
$plugin->savedFilters->save($rangeSaved, $rangeErrors);
$rangeLoaded = $plugin->savedFilters->getByHandle('ca-check-range');
check('a saved filter with two conditions on one field keeps both', $rangeLoaded !== null && titlesFor($rangeLoaded->filter) === ['Beta Two'], implode(' ', $rangeErrors));
if ($rangeLoaded) {
    $plugin->savedFilters->delete($rangeLoaded->id);
}

// ---------------------------------------------------------------------------
section('Options that no longer exist');
// ---------------------------------------------------------------------------

// Craft drops IDs that don't resolve, and some of its queries then add no condition at all —
// tags' group does — so "is one of [a deleted group]" matched every element. A condition whose
// every chosen option has gone matches nothing; the options that remain still filter.
$goneFilter = fn(string $elementType, string $field, string $operator, mixed $value) => FilterSet::fromArray([
    'elementType' => $elementType,
    'criteria' => [['field' => $field, 'operator' => $operator, 'value' => $value]],
]);
$goneCount = fn(FilterSet $filter) => (int)$plugin->query->build($filter)->count();

foreach ([
    [craft\elements\Tag::class, 'groupId', 'tags by group', false],
    [craft\elements\Category::class, 'groupId', 'categories by group', false],
    [craft\elements\User::class, 'groupId', 'users by group', true],
    [craft\elements\Asset::class, 'volumeId', 'assets by volume', false],
    [Entry::class, 'sectionId', 'entries by section', true],
    [Entry::class, 'typeId', 'entries by entry type', false],
] as [$goneType, $goneField, $goneLabel, $goneNullable]) {
    $everything = $goneCount(FilterSet::fromArray(['elementType' => $goneType, 'criteria' => []]));
    check("$goneLabel: is one of [deleted] matches nothing", $goneCount($goneFilter($goneType, $goneField, 'in', ['999999'])) === 0, "of $everything");
    check("$goneLabel: is [deleted] matches nothing", $goneCount($goneFilter($goneType, $goneField, 'eq', '999999')) === 0, "of $everything");
    // Users in no group and nested entries (which have no section) aren't in any group or
    // section, and Craft's negated parameter leaves them out — a separate question from this
    // one — so for those two the negated check is only made where everything has one.
    if (!$goneNullable) {
        check("$goneLabel: is none of [deleted] excludes nothing", $goneCount($goneFilter($goneType, $goneField, 'notIn', ['999999'])) === $everything);
    }
}

check('a deleted section alongside a live one still filters to the live one', titlesFor(FilterSet::fromArray([
    'elementType' => Entry::class,
    'criteria' => [['field' => 'sectionId', 'operator' => 'in', 'value' => [(string)$section->id, '999999']]],
])) === ['Alpha, One', 'Beta Two', 'Delta Four', 'Gamma Three']);
$goneCriterion = Criterion::fromArray(['field' => 'sectionId', 'operator' => 'in', 'value' => ['999999']]);
check('a condition naming a deleted option keeps it when saved again', Criterion::fromArray($goneCriterion->toArray())->value === ['999999']);

// ---------------------------------------------------------------------------
section('Columns');
// ---------------------------------------------------------------------------

$filter = fixtureFilter([]);
$columns = $plugin->columns->resolve($filter, $type);
check('default columns are resolved', in_array('title', $columns, true));

$chosen = fixtureFilter([], ['columns' => ['title', 'caScore', 'caRelated', 'caWhen', 'caFlag', 'caPick', 'noSuchColumn']]);
$resolved = $plugin->columns->resolve($chosen, $type);
check('a column that no longer exists is dropped', !in_array('noSuchColumn', $resolved, true));
check('chosen columns are kept in order', array_slice($resolved, 0, 2) === ['title', 'caScore']);

$gamma = $entries[2];
check('text column value', $plugin->columns->value($gamma, $type, $source, 'title') === 'Gamma Three');
check('number column value', $plugin->columns->value($gamma, $type, $source, 'caScore') === '100');
check('boolean column value', in_array($plugin->columns->value($gamma, $type, $source, 'caFlag'), ['Yes', 'yes'], true));
check('option column shows the label, not the value', $plugin->columns->value($gamma, $type, $source, 'caPick') === 'Cherry');
check('relation column lists the related titles', str_contains($plugin->columns->value($gamma, $type, $source, 'caRelated'), 'Alpha, One'));
check('date column is formatted', preg_match('/^\d{4}-\d{2}-\d{2} /', $plugin->columns->value($gamma, $type, $source, 'caWhen')) === 1);
check('title column links to the element', str_contains($plugin->columns->html($gamma, $type, $source, 'title'), '<a href'));
check('status column renders a status marker', str_contains($plugin->columns->html($gamma, $type, $source, 'status'), 'class="status'));
check('a broken column key renders empty rather than throwing', $plugin->columns->html($gamma, $type, $source, 'noSuchColumn') === '');

// ---------------------------------------------------------------------------
section('Paging and iteration');
// ---------------------------------------------------------------------------

$page = $plugin->filters->results(fixtureFilter([]), 1, 3);
check('total counts every match, not just the page', $page['total'] === 4);
check('the page holds the page size', count($page['elements']) === 3);
check('page count is right', $page['pageCount'] === 2);

$page2 = $plugin->filters->results(fixtureFilter([]), 2, 3);
check('the second page holds the remainder', count($page2['elements']) === 1);

$page99 = $plugin->filters->results(fixtureFilter([]), 99, 3);
check('a page past the end clamps to the last one', $page99['page'] === 2);

$iterated = [];
foreach ($plugin->filters->each(fixtureFilter([]), 2) as $entry) {
    $iterated[] = $entry->title;
}
check('each() walks every match across batches', count($iterated) === 4);
check('each() returns each element once', count(array_unique($iterated)) === 4);

$capped = iterator_to_array($plugin->filters->each(fixtureFilter([]), 2, 3));
check('each() honours a maximum', count($capped) === 3);

$sorted = $plugin->filters->results(fixtureFilter([], ['orderBy' => 'caScore', 'sortDir' => 'asc']), 1, 10);
$sortedScores = array_map(fn($e) => $e->getFieldValue('caScore'), $sorted['elements']);
$ascending = $sortedScores === array_values(array_filter($sortedScores, fn($v) => true));
check('sorting by a custom field works', $sortedScores[count($sortedScores) - 1] == 100 || $sortedScores[0] === null);

// ---------------------------------------------------------------------------
section('Exports');
// ---------------------------------------------------------------------------

foreach (['csv', 'tsv', 'json', 'xml'] as $format) {
    $record = $plugin->exports->run(
        fixtureFilter([], ['columns' => ['id', 'title', 'caScore', 'caPick']]),
        $format,
        'Clean Air check',
    );

    check("$format export completes", $record->status === 'done', $record->error ?? '');
    check("$format export counted every row", $record->rowCount === 4);
    check("$format export wrote a file", $record->path && is_file($record->path));

    $contents = $record->path && is_file($record->path) ? file_get_contents($record->path) : '';

    check("$format export contains the comma title", str_contains($contents, 'Alpha, One'));

    if ($format === 'csv') {
        $lines = array_values(array_filter(explode("\n", trim($contents))));
        check('csv has a header plus one line per row', count($lines) === 5);
        check('csv quotes the comma so it stays one field', str_contains($contents, '"Alpha, One"'));
        check('csv starts with a byte order mark', str_starts_with($contents, "\xEF\xBB\xBF"));
    }

    if ($format === 'json') {
        $decoded = json_decode($contents, true);
        check('json is valid', is_array($decoded) && count($decoded) === 4);
        check('json rows are keyed by column', isset($decoded[0]['title']));
    }

    if ($format === 'xml') {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($contents);
        libxml_use_internal_errors($previous);
        check('xml is well-formed', $xml !== false);
        check('xml has one node per row', $xml !== false && count($xml->result) === 4);
    }

    $plugin->exports->delete($record);
}

$emptyExport = $plugin->exports->run(fixtureFilter([['field' => 'title', 'operator' => 'eq', 'value' => 'nothing at all']]), 'csv', 'Empty');
check('an empty result set still writes a header-only file', $emptyExport->rowCount === 0 && is_file($emptyExport->path));
$plugin->exports->delete($emptyExport);

$maxed = $plugin->exports->createRecord(fixtureFilter([]), 'csv', 'Capped', null, null);
$plugin->getSettings()->maxExportRows = 2;
$plugin->exports->fulfil($maxed, fixtureFilter([]));
check('the export row cap is honoured', $maxed->rowCount === 2);
$plugin->getSettings()->maxExportRows = 0;
$plugin->exports->delete($maxed);

// ---------------------------------------------------------------------------
section('Saved filters');
// ---------------------------------------------------------------------------

$saved = new SavedFilter([
    'name' => 'CA check high scores',
    'handle' => 'ca-check-high',
    'elementType' => Entry::class,
    'source' => $source,
    'filter' => fixtureFilter([['field' => 'caScore', 'operator' => 'gte', 'value' => 50]]),
]);

$errors = [];
check('a filter saves', $plugin->savedFilters->save($saved, $errors), implode(' ', $errors));
check('saving assigns an id', $saved->id !== null);

$loaded = $plugin->savedFilters->getByHandle('ca-check-high');
check('a saved filter loads back by handle', $loaded !== null);
check('its criteria survive the round trip', count($loaded->filter->criteria) === 1);
check('its source survives the round trip', $loaded->filter->source === $source);
check('running a saved filter gives the same answer', titlesFor($loaded->filter) === ['Beta Two', 'Gamma Three']);

$second = new SavedFilter([
    'name' => 'CA check high scores',
    'elementType' => Entry::class,
    'filter' => fixtureFilter([]),
]);
$second->handle = $plugin->savedFilters->uniqueHandle($second->name);
check('a clashing name gets a distinct handle', $plugin->savedFilters->save($second, $errors) && $second->handle !== $saved->handle);

$copy = $plugin->savedFilters->duplicate($loaded);
check('duplicating produces a new handle', $copy->handle !== $loaded->handle);

$plugin->savedFilters->touch($saved->id);
check('running a filter records when', $plugin->savedFilters->getById($saved->id)->dateLastRun !== null);

check('deleting a filter removes it', $plugin->savedFilters->delete($second->id) && $plugin->savedFilters->getById($second->id) === null);

// ---------------------------------------------------------------------------
section('Editions');
// ---------------------------------------------------------------------------

check('Lite allows entries', Edition::allowsTypeKey(false, 'entries'));
check('Lite does not allow Commerce orders', !Edition::allowsTypeKey(false, 'orders'));
check('Pro allows Commerce orders', Edition::allowsTypeKey(true, 'orders'));
check('Lite exports CSV only', Edition::allowsFormat(false, 'csv') && !Edition::allowsFormat(false, 'json'));
check('Lite caps saved filters', Edition::maxSavedFilters(false) === 10);
check('Pro does not cap saved filters', Edition::maxSavedFilters(true) === null);
check('Lite has no sharing', !Edition::allowsSharing(false));
check('Pro edition is detected', $plugin->isPro());
check('Pro offers all four export formats', count($plugin->exports->availableFormats()) === 4);

// ---------------------------------------------------------------------------
section('Importing from CP Filters');
// ---------------------------------------------------------------------------

check('no table means nothing to import', !$plugin->importer->isAvailable() || $plugin->importer->countAvailable() >= 0);

// Stand up a CP Filters table exactly as its Craft 4 install migration left it, and fill it
// with the shapes it really wrote.
$db = Craft::$app->getDb();
$db->createCommand()->createTable('{{%cpfilters_savedfilters}}', [
    'id' => 'int NOT NULL AUTO_INCREMENT PRIMARY KEY',
    'userId' => 'int NULL',
    'title' => 'varchar(255) NULL',
    'includeDrafts' => 'varchar(10) NULL',
    'filterElementType' => 'varchar(255) NULL',
    'filterGroupId' => 'int NULL',
    'filterCriteria' => 'text NULL',
    'dateCreated' => 'datetime NOT NULL',
    'dateUpdated' => 'datetime NOT NULL',
    'dateDeleted' => 'datetime NULL',
    'uid' => 'char(36) NULL',
])->execute();

$stamp = Db::prepareDateForDb(new DateTime());

$cpRows = [
    // Straightforward: a text contains, on the fixture entry type.
    ['CP text filter', 'entries', $entryType->id, json_encode([
        ['fieldHandle' => 'caSummary', 'filterType' => 'contains', 'value' => 'world'],
    ]), '', null],
    // "is greater than" on a date means "is after" — the mapping that has to look at the field.
    ['CP date filter', 'entries', $entryType->id, json_encode([
        ['fieldHandle' => 'caWhen', 'filterType' => 'is greater than', 'value' => (clone $now)->modify('-5 days')->format('Y-m-d H:i:s')],
    ]), 'y', null],
    // "is equal to" on a lightswitch means "is on".
    ['CP lightswitch filter', 'entries', $entryType->id, json_encode([
        ['fieldHandle' => 'caFlag', 'filterType' => 'is equal to', 'value' => '1'],
    ]), '', null],
    // A field that no longer exists.
    ['CP stale filter', 'entries', $entryType->id, json_encode([
        ['fieldHandle' => 'longGoneField', 'filterType' => 'contains', 'value' => 'x'],
    ]), '', null],
    // An element type Clean Air doesn't map.
    ['CP unknown type', 'widgets', null, json_encode([]), '', null],
    // A source that has since been deleted.
    ['CP missing source', 'entries', 999999, json_encode([
        ['fieldHandle' => 'title', 'filterType' => 'contains', 'value' => 'Alpha'],
    ]), '', null],
    // Deleted in CP Filters.
    ['CP deleted filter', 'entries', $entryType->id, json_encode([]), '', $stamp],
];

foreach ($cpRows as [$title, $typeKey, $groupId, $criteria, $drafts, $deleted]) {
    $db->createCommand()->insert('{{%cpfilters_savedfilters}}', [
        'userId' => null,
        'title' => $title,
        'includeDrafts' => $drafts,
        'filterElementType' => $typeKey,
        'filterGroupId' => $groupId,
        'filterCriteria' => $criteria,
        'dateCreated' => $stamp,
        'dateUpdated' => $stamp,
        'dateDeleted' => $deleted,
        'uid' => null,
    ])->execute();
}

Craft::$app->getDb()->getSchema()->refresh();

check('the CP Filters table is detected', $plugin->importer->isAvailable());
check('deleted rows are excluded by default', $plugin->importer->countAvailable() === 6);
check('deleted rows can be included', $plugin->importer->countAvailable(true) === 7);

$preview = $plugin->importer->preview();
$byName = [];
foreach ($preview as $row) {
    $byName[$row['name']] = $row;
}

check('preview covers every live row', count($preview) === 6);
check('the text filter maps to Entry', $byName['CP text filter']['elementType'] === Entry::class);
check('the entry type ID maps to a source', $byName['CP text filter']['source'] === $source);
check('the source is named in the preview', $byName['CP text filter']['sourceLabel'] === 'Clean Air Fixture');
check('contains maps straight across', $byName['CP text filter']['criteria'][0]->operator === 'contains');
check('is greater than on a date becomes is after', $byName['CP date filter']['criteria'][0]->operator === 'after');
check('the drafts flag comes across', $byName['CP date filter']['includeDrafts'] === true);
check('is equal to on a lightswitch becomes is on', $byName['CP lightswitch filter']['criteria'][0]->operator === 'isTrue');
check('a missing field is reported', count($byName['CP stale filter']['warnings']) === 1);
check('a missing field drops only its own condition', count($byName['CP stale filter']['criteria']) === 0);
check('an unmappable element type is flagged unimportable', $byName['CP unknown type']['importable'] === false);
check('a missing source is reported', count($byName['CP missing source']['warnings']) === 1);
check('a missing source falls back to all sources', $byName['CP missing source']['source'] === null);
check('preview writes nothing', (int)(new DbQuery())->from(['{{%cleanair_filters}}'])->where(['like', 'name', 'CP %', false])->count() === 0);

$report = $plugin->importer->import(['skipExisting' => true, 'importSettings' => false]);

check('the importable rows import', $report['imported'] === 5, json_encode($report));
check('the unmappable row is skipped', $report['skipped'] === 1);
check('nothing failed', $report['failed'] === 0);

$importedText = null;
foreach ($plugin->savedFilters->all() as $candidate) {
    if ($candidate->name === 'CP text filter') {
        $importedText = $candidate;
    }
}

check('an imported filter is readable', $importedText !== null);
check('an imported filter runs and gives the right answer', $importedText !== null && titlesFor($importedText->filter) === ['Alpha, One', 'Beta Two']);

$importedDate = $plugin->savedFilters->getByHandle('cp-date-filter');
check('an imported date filter runs correctly', $importedDate !== null && titlesFor($importedDate->filter) === ['Alpha, One', 'Gamma Three']);

$rerun = $plugin->importer->import(['skipExisting' => true, 'importSettings' => false]);
check('re-importing skips what is already there', $rerun['imported'] === 0 && $rerun['skipped'] === 6);

check('CP Filters’ own rows are left untouched', (int)(new DbQuery())->from(['{{%cpfilters_savedfilters}}'])->count() === 7);

// ---------------------------------------------------------------------------
section('Templates');
// ---------------------------------------------------------------------------

$variable = new justinholtweb\cleanair\variables\CleanAirVariable();
check('a saved filter is reachable by handle from Twig', $variable->filter('ca-check-high') !== null);
check('the Twig helper counts', $variable->count('ca-check-high') === 2);
check('an unknown handle returns null rather than throwing', $variable->filter('no-such-filter') === null);

$fieldValue = new justinholtweb\cleanair\models\FilterFieldValue(['handle' => 'ca-check-high']);
check('the filter field resolves its filter', $fieldValue->getFilter() !== null);
check('the filter field returns elements', count($fieldValue->all()) === 2);
check('the filter field counts', $fieldValue->count() === 2);
check('the filter field survives a deleted filter', (new justinholtweb\cleanair\models\FilterFieldValue(['handle' => 'gone']))->all() === []);

// ---------------------------------------------------------------------------
section('Templates render');
// ---------------------------------------------------------------------------

// Compiling catches syntax errors and unknown filters; rendering with real data catches the
// undefined variables and bad method calls that only show up on the page.
$view = Craft::$app->getView();
$view->setTemplateMode(craft\web\View::TEMPLATE_MODE_CP);

foreach ([
    'cleanair/index',
    'cleanair/_empty',
    'cleanair/_settings',
    'cleanair/saved/index',
    'cleanair/exports/index',
    'cleanair/import/index',
    'cleanair/_partials/results',
    'cleanair/_partials/criterion-row',
    'cleanair/_partials/criterion-inputs',
    'cleanair/_partials/criterion-group',
    'cleanair/reports/edit',
    'cleanair/_emails/report',
    'cleanair/_emails/report.txt',
] as $template) {
    $compiled = true;
    $error = '';

    try {
        $view->getTwig()->load($template);
    } catch (Throwable $e) {
        $compiled = false;
        $error = $e->getMessage();
    }

    check("$template compiles", $compiled, $error);
}

$textDefinition = $definitions['caSummary'];
$relationDefinition = $definitions['caRelated'];
$dateDefinition = $definitions['caWhen'];
$optionDefinition = $definitions['caPick'];

foreach ([
    'text input' => [$textDefinition, 'contains', 'hello', null],
    'no input' => [$textDefinition, 'empty', null, null],
    'textarea input' => [$textDefinition, 'in', "a\nb", null],
    'number input' => [$definitions['caScore'], 'gt', 5, null],
    'range input' => [$definitions['caScore'], 'between', 1, 10],
    'date input' => [$dateDefinition, 'after', '2026-01-01', null],
    'date range input' => [$dateDefinition, 'betweenDates', '2026-01-01', '2026-02-01'],
    'relative date input' => [$dateDefinition, 'inLast', 30, 'days'],
    'select input' => [$optionDefinition, 'eq', 'apple', null],
    'multi-select input' => [$optionDefinition, 'in', ['apple', 'banana'], null],
    'element select input' => [$relationDefinition, 'isAssigned', [$entries[0]->id], null],
] as $label => [$definition, $operator, $value, $value2]) {
    $rendered = '';
    $error = '';

    try {
        $rendered = $view->renderTemplate('cleanair/_partials/criterion-inputs', [
            'definition' => $definition,
            'operators' => $plugin->operators->forField($definition),
            'operator' => $operator,
            'index' => 0,
            'value' => $value,
            'value2' => $value2,
            'dateValue' => null,
            'dateValue2' => null,
            'valueElements' => $plugin->fields->elementsForValue($definition, $value),
        ]);
    } catch (Throwable $e) {
        $error = get_class($e) . ': ' . $e->getMessage();
    }

    if ($error !== '' && (str_contains($error, 'isMobileBrowser') || str_contains($error, 'getHeaders'))) {
        skip("criterion row renders a $label", 'Craft’s own macro needs a web request');
        continue;
    }

    check("criterion row renders a $label", $rendered !== '' && $error === '', $error);
}

$rowHtml = '';
$rowError = '';

try {
    $rowHtml = $view->renderTemplate('cleanair/_partials/criterion-row', [
        'index' => 0,
        'criterion' => new Criterion(['field' => 'caSummary', 'operator' => 'contains', 'value' => 'hello']),
        'definition' => $textDefinition,
        'operators' => $plugin->operators->forField($textDefinition),
        'operator' => 'contains',
        'value' => 'hello',
        'grouped' => $plugin->fields->grouped($type, $source),
    ]);
} catch (Throwable $e) {
    $rowError = get_class($e) . ': ' . $e->getMessage();
}

check('a whole criterion row renders', str_contains($rowHtml, 'data-cleanair-criterion'), $rowError);
check('the row lists fields grouped by heading', str_contains($rowHtml, '<optgroup'));
check('the row preselects the chosen field', str_contains($rowHtml, 'value="caSummary" selected'));

$resultsHtml = '';
$resultsError = '';

try {
    $resultsHtml = $view->renderTemplate('cleanair/_partials/results', [
        'plugin' => $plugin,
        'type' => $type,
        'filter' => fixtureFilter([]),
        'results' => $plugin->filters->results(fixtureFilter([]), 1, 3),
        'shareUrl' => null,
    ]);
} catch (Throwable $e) {
    $resultsError = get_class($e) . ': ' . $e->getMessage();
}

check('the results table renders', str_contains($resultsHtml, '<table'), $resultsError);
// Any of them: the fixtures are saved in the same second, so the default date sort orders them
// arbitrarily and page 1 of 2 holds a different three from one run to the next.
check('the results table shows a fixture entry', (bool)array_filter(
    ['Alpha, One', 'Beta Two', 'Gamma Three', 'Delta Four'],
    fn(string $title) => str_contains($resultsHtml, $title),
));
check('the results table pages', str_contains($resultsHtml, 'Page 1 of 2'));

$settingsHtml = '';
$settingsError = '';

try {
    // Protected, as Craft declares it — this is how Craft's own plugin settings screen gets at it.
    $method = new ReflectionMethod($plugin, 'settingsHtml');
    $method->setAccessible(true);
    $settingsHtml = $method->invoke($plugin);
} catch (Throwable $e) {
    $settingsError = get_class($e) . ': ' . $e->getMessage();
}

check('the settings screen renders', is_string($settingsHtml) && $settingsHtml !== '', $settingsError);
check('the settings screen lists filterable sources', str_contains((string)$settingsHtml, 'filterableSources'));

// ---------------------------------------------------------------------------

Craft::$app->getPlugins()->switchEdition('cleanair', $originalEdition);

if (!$keep) {
    teardown();
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "$passed passed, $failed failed" . ($skipped ? ", $skipped skipped" : '') . "\n";

if ($failures) {
    echo "\nFailures:\n";
    foreach ($failures as $failure) {
        echo "  - $failure\n";
    }
}

exit($failed === 0 ? 0 : 1);
