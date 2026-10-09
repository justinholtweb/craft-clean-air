<?php
/**
 * Scheduled reports and condition groups over HTTP: who may open, save and test a schedule, and
 * the builder's group markup.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-cleanair/tests/integration/reports-http.php
 *
 * Signs in as two editors who can see one section, save filters and export: one with the
 * schedule permission, one without. Every refusal is paired with something the same user *is*
 * allowed, so a pass means "refused", not "broken". Self-cleaning.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\Entry;
use craft\elements\User;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use justinholtweb\cleanair\models\FilterSet;
use justinholtweb\cleanair\models\SavedFilter;
use justinholtweb\cleanair\Plugin;
use justinholtweb\cleanair\records\ExportRecord;
use justinholtweb\cleanair\services\Permissions;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();
    } catch (Throwable $e) {
        $result = get_class($e) . ': ' . $e->getMessage();
    }

    if ($result === true) {
        $passed++;
        echo "  ✓ $label\n";
    } else {
        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : var_export($result, true)) . "\n";
    }
}

Craft::$app->getPlugins()->loadPlugins();

$plugin = Plugin::getInstance();

if (!$plugin->isPro()) {
    echo "Scheduled reports and groups are Pro; the harness has Clean Air on Lite.\n";
    exit(1);
}

$run = bin2hex(random_bytes(3));
$password = 'ca-' . bin2hex(random_bytes(12));
$users = [];
$filters = [];

register_shutdown_function(function() use (&$users, &$filters) {
    $plugin = Plugin::getInstance();
    $ids = array_map(fn($u) => $u->id, $users);

    foreach (ExportRecord::find()->where(['userId' => $ids ?: [0]])->all() as $record) {
        $plugin->exports->delete($record);
    }
    foreach ($filters as $filter) {
        $plugin->savedFilters->delete((int)$filter->id);
    }
    foreach ($users as $user) {
        Craft::$app->getElements()->deleteElement($user, true);
    }
});

$visible = null;
foreach (Craft::$app->getEntries()->getAllSections() as $candidate) {
    if (Entry::find()->sectionId($candidate->id)->status(null)->exists()) {
        $visible = $candidate;
        break;
    }
}

$siteUid = Craft::$app->getSites()->getPrimarySite()->uid;

function makeUser(string $name, array $permissions): User
{
    global $run, $password, $users;

    $user = new User();
    $user->username = "cleanair-rh-$name-$run";
    $user->email = "cleanair-rh-$name-$run@example.com";
    $user->newPassword = $password;
    Craft::$app->getElements()->saveElement($user, false);
    Craft::$app->getUsers()->activateUser($user);
    Craft::$app->getUserPermissions()->saveUserPermissions($user->id, array_map('strtolower', $permissions));
    $users[] = $user;

    return $user;
}

$base = [
    'accessCp', 'accessPlugin-cleanair', "editSite:$siteUid", "viewEntries:$visible->uid",
    Permissions::VIEW, Permissions::EXPORT, Permissions::SAVE,
];

$scheduler = makeUser('scheduler', array_merge($base, [Permissions::SCHEDULE]));
$plain = makeUser('plain', $base);
$outsider = makeUser('outsider', ['accessCp']);

function makeSaved(User $owner, string $name): SavedFilter
{
    global $visible, $run, $filters;

    $saved = new SavedFilter([
        'name' => "Report http $name $run",
        'elementType' => Entry::class,
        'source' => "section:$visible->id",
        'filter' => FilterSet::fromArray(['elementType' => Entry::class, 'source' => "section:$visible->id"]),
        'userId' => $owner->id,
    ]);
    $errors = [];
    Plugin::getInstance()->savedFilters->save($saved, $errors) || throw new RuntimeException(implode(' ', $errors));
    $filters[] = $saved;

    return $saved;
}

$mine = makeSaved($scheduler, 'mine');
$theirs = makeSaved($plain, 'theirs');

function client(): Client
{
    return new Client(['base_uri' => 'http://localhost/', 'cookies' => new CookieJar(), 'http_errors' => false, 'allow_redirects' => false]);
}

function csrf(Client $http): string
{
    $info = json_decode((string)$http->get('index.php?p=actions/users/session-info', ['headers' => ['Accept' => 'application/json']])->getBody(), true);

    return (string)($info['csrfTokenValue'] ?? '');
}

function login(User $user): Client
{
    global $password;

    $http = client();
    $response = $http->post('index.php?p=actions/users/login', [
        'headers' => ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'],
        'form_params' => ['loginName' => $user->username, 'password' => $password, 'CRAFT_CSRF_TOKEN' => csrf($http)],
    ]);

    if ($response->getStatusCode() !== 200) {
        throw new RuntimeException("Could not sign in as $user->username: {$response->getStatusCode()}");
    }

    return $http;
}

function post(Client $http, string $action, array $params, bool $withCsrf = true): int
{
    if ($withCsrf) {
        $params['CRAFT_CSRF_TOKEN'] = csrf($http);
    }

    return $http->post("index.php?p=admin/actions/$action", ['form_params' => $params])->getStatusCode();
}

$asScheduler = login($scheduler);
$asPlain = login($plain);
$anonymous = client();

$schedule = fn(array $overrides = []) => ['filterId' => $mine->id, 'report' => array_merge([
    'enabled' => '1',
    'frequency' => 'weekly',
    'weekday' => '1',
    'hour' => '8',
    'recipientIds' => [(string)$scheduler->id],
    'format' => 'csv',
    'sendWhen' => 'results',
    'delivery' => 'attach',
], $overrides)];

echo "\nThe schedule permission\n";

check('a guest is not let anywhere near it', function() use ($anonymous, $schedule) {
    $status = post($anonymous, 'cleanair/reports/save', $schedule());

    return in_array($status, [302, 403], true) && Plugin::getInstance()->reports->getByFilterId((int)$GLOBALS['mine']->id) === null ?: "status $status";
});

check('without the permission: the saved filters list opens, the schedule screen doesn’t', function() use ($asPlain, $theirs) {
    $list = $asPlain->get('admin/cleanair/saved')->getStatusCode();
    $page = $asPlain->get("admin/cleanair/saved/$theirs->id/report")->getStatusCode();

    return $list === 200 && $page === 403 ?: "list $list, schedule $page";
});

check('without the permission: saving and testing are refused, even on their own filter', function() use ($asPlain, $theirs, $plain) {
    $save = post($asPlain, 'cleanair/reports/save', ['filterId' => $theirs->id, 'report' => ['recipientIds' => [(string)$plain->id]]]);
    $test = post($asPlain, 'cleanair/reports/send-test', ['filterId' => $theirs->id]);

    return $save === 403 && $test === 403 && Plugin::getInstance()->reports->getByFilterId((int)$theirs->id) === null ?: "save $save, test $test";
});

check('without the permission: no Email… button on the list', function() use ($asPlain) {
    return !str_contains((string)$asPlain->get('admin/cleanair/saved')->getBody(), '/report"') ?: 'button shown';
});

check('with it: the schedule screen opens, for their own filter', function() use ($asScheduler, $mine) {
    $status = $asScheduler->get("admin/cleanair/saved/$mine->id/report")->getStatusCode();

    return $status === 200 ?: "status $status";
});

check('with it: someone else’s private filter is still refused', function() use ($asScheduler, $theirs, $scheduler) {
    $page = $asScheduler->get("admin/cleanair/saved/$theirs->id/report")->getStatusCode();
    $save = post($asScheduler, 'cleanair/reports/save', ['filterId' => $theirs->id, 'report' => ['recipientIds' => [(string)$scheduler->id]]]);

    return $page === 403 && $save === 403 ?: "page $page, save $save";
});

echo "\nRequests\n";

check('saving is POST only', function() use ($asScheduler, $mine) {
    $status = $asScheduler->get("index.php?p=admin/actions/cleanair/reports/save&filterId=$mine->id")->getStatusCode();

    return in_array($status, [400, 405], true) ?: "status $status";
});

check('saving without a CSRF token is refused', function() use ($asScheduler, $schedule, $mine) {
    $status = post($asScheduler, 'cleanair/reports/save', $schedule(), false);

    return $status === 400 && Plugin::getInstance()->reports->getByFilterId((int)$mine->id) === null ?: "status $status";
});

check('a recipient who couldn’t run the filter is refused with the reason, and nothing is saved', function() use ($asScheduler, $schedule, $outsider, $mine) {
    $response = $asScheduler->post('index.php?p=admin/cleanair/saved/' . $mine->id . '/report', [
        'form_params' => array_merge($schedule(['recipientIds' => [(string)$outsider->id]]), [
            'action' => 'cleanair/reports/save',
            'CRAFT_CSRF_TOKEN' => csrf($asScheduler),
        ]),
    ]);
    $html = (string)$response->getBody();

    return $response->getStatusCode() === 200 && str_contains($html, 'can’t use Clean Air')
        && Plugin::getInstance()->reports->getByFilterId((int)$mine->id) === null
        ?: 'status ' . $response->getStatusCode();
});

check('a valid schedule saves and runs as whoever saved it, whatever the form says', function() use ($asScheduler, $schedule, $scheduler, $mine) {
    $status = post($asScheduler, 'cleanair/reports/save', array_merge($schedule(['ownerId' => '1']), ['ownerId' => '1']));
    $report = Plugin::getInstance()->reports->getByFilterId((int)$mine->id);

    return $status === 302 && $report && $report->ownerId === (int)$scheduler->id && $report->recipientIds === [(int)$scheduler->id]
        ?: "status $status, owner " . ($report->ownerId ?? 'none');
});

check('the schedule screen’s forms are siblings, never nested', function() use ($asScheduler, $mine) {
    $html = (string)$asScheduler->get("admin/cleanair/saved/$mine->id/report")->getBody();
    $content = substr($html, (int)strpos($html, 'cleanair/reports/save'));
    $saveForm = substr($content, 0, (int)strpos($content, '</form>'));

    return str_contains($html, 'cleanair/reports/send-test') && !str_contains($saveForm, '<form') ?: 'nested form';
});

check('the list shows the schedule', function() use ($asScheduler) {
    return str_contains((string)$asScheduler->get('admin/cleanair/saved')->getBody(), 'Weekly on') ?: 'not shown';
});

check('a test send goes through, then cools down for half a minute', function() use ($asScheduler, $scheduler, $mine) {
    $count = fn() => (int)ExportRecord::find()->where(['userId' => $scheduler->id, 'filterId' => $mine->id])->count();
    Craft::$app->getCache()->delete('cleanair:reports:test:' . $scheduler->id);
    $before = $count();
    $first = post($asScheduler, 'cleanair/reports/send-test', ['filterId' => $mine->id]);
    $afterFirst = $count();
    $second = post($asScheduler, 'cleanair/reports/send-test', ['filterId' => $mine->id]);
    $afterSecond = $count();

    return $first === 302 && $second === 302 && $afterFirst === $before + 1 && $afterSecond === $afterFirst
        ?: "$first/$second, exports $before → $afterFirst → $afterSecond";
});

check('a test send is POST only', function() use ($asScheduler, $mine) {
    $status = $asScheduler->get("index.php?p=admin/actions/cleanair/reports/send-test&filterId=$mine->id")->getStatusCode();

    return in_array($status, [400, 405], true) ?: "status $status";
});

check('removing a schedule needs the permission too', function() use ($asPlain, $asScheduler, $mine) {
    $refused = post($asPlain, 'cleanair/reports/delete', ['filterId' => $mine->id]);
    $stillThere = Plugin::getInstance()->reports->getByFilterId((int)$mine->id) !== null;
    $removed = post($asScheduler, 'cleanair/reports/delete', ['filterId' => $mine->id]);

    return $refused === 403 && $stillThere && $removed === 302 && Plugin::getInstance()->reports->getByFilterId((int)$mine->id) === null
        ?: "refused $refused, removed $removed";
});

echo "\nCondition groups in the builder\n";

check('a group in the URL renders as a group, its rows named inside it', function() use ($asScheduler, $visible) {
    $query = http_build_query(['filter' => [
        'elementType' => Entry::class,
        'source' => "section:$visible->id",
        'criteria' => [
            ['field' => 'title', 'operator' => 'contains', 'value' => 'a'],
            ['type' => 'group', 'matchMode' => 'any', 'criteria' => [
                ['field' => 'title', 'operator' => 'contains', 'value' => 'b'],
                ['field' => 'title', 'operator' => 'contains', 'value' => 'c'],
            ]],
        ],
    ], 'run' => 1]);
    $html = (string)$asScheduler->get("admin/cleanair/type/entries?$query")->getBody();

    return str_contains($html, 'data-cleanair-group data-name="filter[criteria][1]"')
        && str_contains($html, 'name="filter[criteria][1][criteria][1][field]"')
        && str_contains($html, 'name="filter[criteria][1][type]" value="group"')
        && str_contains($html, 'data-cleanair-add-group')
        ?: 'group markup missing';
});

check('the row endpoint names inputs inside a group, and ignores a name that isn’t one', function() use ($asScheduler) {
    $row = fn(string $name) => json_decode((string)$asScheduler->post('index.php?p=admin/actions/cleanair/filters/criterion-row', [
        'headers' => ['Accept' => 'application/json'],
        'form_params' => ['typeKey' => 'entries', 'index' => 4, 'name' => $name, 'field' => 'title', 'CRAFT_CSRF_TOKEN' => csrf($asScheduler)],
    ])->getBody(), true)['html'] ?? '';

    $inGroup = $row('filter[criteria][2][criteria][4]');
    $hostile = $row('x"><script>alert(1)</script>');

    return str_contains($inGroup, 'name="filter[criteria][2][criteria][4][operator]"')
        && str_contains($hostile, 'name="filter[criteria][4][operator]"') && !str_contains($hostile, '<script>')
        ?: 'names wrong';
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
