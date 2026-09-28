<?php
/**
 * What a limited editor can reach through Clean Air — checked over HTTP.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-cleanair/tests/integration/trust.php
 *
 * Signs in as a user with Clean Air's view and export permissions who can see exactly one section
 * and no users, then asks for what they shouldn't get: a count and an export of users, and entries
 * from "All sources". Until 5.0.1 the builder screen checked all of this but count and export took
 * the posted filter as it came, and "All sources" reached every section. Each check is paired with
 * one the same user *is* allowed, so a pass means "refused", not "broken".
 *
 * Idempotent and self-cleaning: the user and any export record are removed at the end.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\Entry;
use craft\elements\User;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use justinholtweb\cleanair\Plugin;
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
$run = substr(bin2hex(random_bytes(3)), 0, 6);
$password = 'ca-' . bin2hex(random_bytes(12));
$user = null;
$exportsBefore = (int)(new craft\db\Query())->from('{{%cleanair_exports}}')->count();

register_shutdown_function(function() use (&$user) {
    if ($user !== null) {
        // Through the service, which deletes the file as well as the row: an export that got
        // through would be a CSV of personal data left in storage.
        foreach (\justinholtweb\cleanair\records\ExportRecord::findAll(['userId' => $user->id]) as $record) {
            Plugin::getInstance()->exports->delete($record);
        }
        Craft::$app->getElements()->deleteElement($user, true);
    }
});

// One section this editor can see, among several they can't.
$sections = Craft::$app->getEntries()->getAllSections();

if (count($sections) < 2) {
    echo "This check needs at least two sections in the install.\n";
    exit(1);
}

$visible = null;

foreach ($sections as $candidate) {
    if (Entry::find()->sectionId($candidate->id)->status(null)->exists()) {
        $visible = $candidate;
        break;
    }
}

$siteUid = Craft::$app->getSites()->getPrimarySite()->uid;

$user = new User();
$user->username = "cleanair-editor-$run";
$user->email = "cleanair-editor-$run@example.com";
$user->newPassword = $password;
Craft::$app->getElements()->saveElement($user, false);
Craft::$app->getUsers()->activateUser($user);
Craft::$app->getUserPermissions()->saveUserPermissions($user->id, [
    'accesscp', 'accessplugin-cleanair', "editsite:$siteUid", "viewentries:$visible->uid",
    strtolower(Permissions::VIEW), strtolower(Permissions::EXPORT),
]);

$http = new Client(['base_uri' => 'http://localhost/', 'cookies' => new CookieJar(), 'http_errors' => false, 'allow_redirects' => false]);

$csrf = static function() use ($http): string {
    $info = json_decode((string)$http->get('index.php?p=actions/users/session-info', ['headers' => ['Accept' => 'application/json']])->getBody(), true);

    return (string)($info['csrfTokenValue'] ?? '');
};

$login = $http->post('index.php?p=actions/users/login', [
    'headers' => ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'],
    'form_params' => ['loginName' => $user->username, 'password' => $password, 'CRAFT_CSRF_TOKEN' => $csrf()],
]);

if ($login->getStatusCode() !== 200) {
    echo "Could not sign in as the test user: {$login->getStatusCode()}\n";
    exit(1);
}

$count = static function(array $filter) use ($http, $csrf): array {
    $response = $http->post('index.php?p=admin/actions/cleanair/filters/count', [
        'headers' => ['Accept' => 'application/json'],
        'form_params' => ['filter' => $filter, 'CRAFT_CSRF_TOKEN' => $csrf()],
    ]);

    return [$response->getStatusCode(), json_decode((string)$response->getBody(), true)['count'] ?? null];
};

$exportUsers = static fn() => $http->post('index.php?p=admin/actions/cleanair/filters/export', [
    'form_params' => ['filter' => ['elementType' => User::class], 'format' => 'csv', 'CRAFT_CSRF_TOKEN' => $csrf()],
])->getStatusCode();

echo "\nElement types\n";

check('counting users is refused to someone who cannot view users', function() use ($count) {
    [$status, $number] = $count(['elementType' => User::class]);

    return $status === 403 ?: "status $status, count " . var_export($number, true);
});

check('exporting users is refused, and writes no export', function() use ($exportUsers, $exportsBefore) {
    $status = $exportUsers();
    $after = (int)(new craft\db\Query())->from('{{%cleanair_exports}}')->count();

    return $status === 403 && $after === $exportsBefore ?: "status $status, exports $exportsBefore → $after";
});

echo "\nSources\n";

check('the section they can view can be counted', function() use ($count, $visible) {
    [$status, $number] = $count(['elementType' => Entry::class, 'source' => "section:$visible->id"]);

    return $status === 200 && $number > 0 ?: "status $status, count " . var_export($number, true);
});

check('“All sources” is refused, since it would include sections they cannot view', function() use ($count) {
    [$status, $number] = $count(['elementType' => Entry::class, 'source' => '']);

    return $status === 403 ?: "status $status, count " . var_export($number, true);
});

check('the builder does not offer “All sources”, and starts on their section', function() use ($http, $visible) {
    $html = (string)$http->get('admin/cleanair/type/entries')->getBody();

    return !str_contains($html, '>All sources<')
        && preg_match('/value="section:' . $visible->id . '" selected/', $html) === 1
        ?: 'All sources offered, or their section not selected';
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
