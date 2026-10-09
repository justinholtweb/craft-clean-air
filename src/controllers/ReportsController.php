<?php

namespace justinholtweb\cleanair\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use justinholtweb\cleanair\models\Report;
use justinholtweb\cleanair\models\SavedFilter;
use justinholtweb\cleanair\Plugin;
use Throwable;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * A saved filter's email schedule: set it up, change it, remove it, and send a test.
 *
 * Every action needs the schedule permission (Pro) *and* the right to change the saved filter
 * itself — a schedule is part of the filter, so someone who can only read a shared filter can't
 * start mailing it out. The schedule runs as whoever saved it last, so saving it is also saying
 * "run this with my permissions".
 */
class ReportsController extends Controller
{
    /** One test per user per this many seconds. */
    public const TEST_COOLDOWN = 30;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        if (!Plugin::getInstance()->permissions->canView()) {
            throw new ForbiddenHttpException(Craft::t('cleanair', 'You don’t have permission to use Clean Air.'));
        }

        return true;
    }

    public function actionEdit(int $filterId, ?Report $report = null): Response
    {
        $plugin = Plugin::getInstance();
        $saved = $this->savedFilter($filterId);
        $report ??= $plugin->reports->getByFilterId($saved->id) ?? new Report([
            'filterId' => $saved->id,
            'recipientIds' => [Craft::$app->getUser()->getId()],
        ]);

        $type = $plugin->filters->type($saved->filter);

        return $this->renderTemplate('cleanair/reports/edit', [
            'plugin' => $plugin,
            'saved' => $saved,
            'report' => $report,
            'personal' => $type->containsPersonalData(),
            'typeLabel' => $type->label(),
            'recipients' => $plugin->reports->users($report->recipientIds),
            'formats' => $plugin->exports->availableFormats(),
            'nextDue' => $report->id ? $plugin->reports->nextDueAt($report) : null,
            'owner' => $report->id ? $plugin->reports->owner($report) : null,
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $request = Craft::$app->getRequest();
        $user = Craft::$app->getUser()->getIdentity();
        $saved = $this->savedFilter((int)$request->getRequiredBodyParam('filterId'));

        $report = $plugin->reports->getByFilterId($saved->id) ?? new Report(['filterId' => $saved->id]);
        $posted = $request->getBodyParam('report');
        $report->setAttributes(is_array($posted) ? $posted : []);

        // Whoever saves it is who it runs as. Not something a form field gets to say.
        $report->filterId = $saved->id;
        $report->ownerId = $user?->id;

        $this->checkReport($report, $saved);

        if ($report->hasErrors() || !$plugin->reports->save($report)) {
            $this->setFailFlash(Craft::t('cleanair', 'Couldn’t save the schedule.'));

            Craft::$app->getUrlManager()->setRouteParams(['report' => $report]);

            return null;
        }

        $this->setSuccessFlash(Craft::t('cleanair', 'Schedule saved.'));

        return $this->redirect(UrlHelper::cpUrl('cleanair/saved/' . $saved->id . '/report'));
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $saved = $this->savedFilter((int)Craft::$app->getRequest()->getRequiredBodyParam('filterId'));
        $report = $plugin->reports->getByFilterId($saved->id);

        if ($report) {
            $plugin->reports->delete((int)$report->id);
        }

        $this->setSuccessFlash(Craft::t('cleanair', 'Schedule removed.'));

        return $this->redirect(UrlHelper::cpUrl('cleanair/saved'));
    }

    /**
     * "Send me a test now". To the person pressing it, run with their own permissions, marked as
     * a test, and never counted as this period's report.
     */
    public function actionSendTest(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $user = Craft::$app->getUser()->getIdentity();
        $saved = $this->savedFilter((int)Craft::$app->getRequest()->getRequiredBodyParam('filterId'));
        $report = $plugin->reports->getByFilterId($saved->id);
        $back = UrlHelper::cpUrl('cleanair/saved/' . $saved->id . '/report');

        if (!$report || !$user) {
            $this->setFailFlash(Craft::t('cleanair', 'Save the schedule first.'));

            return $this->redirect($back);
        }

        if (!Craft::$app->getCache()->add('cleanair:reports:test:' . $user->id, 1, self::TEST_COOLDOWN)) {
            $this->setFailFlash(Craft::t('cleanair', 'A test report was sent a moment ago. Give it half a minute.'));

            return $this->redirect($back);
        }

        $reason = null;

        try {
            $sent = $plugin->reports->sendTest($report, $user, $reason);
        } catch (Throwable $e) {
            Craft::error('Could not send a test report: ' . $e->getMessage(), Plugin::LOG_CATEGORY);
            $sent = 0;
        }

        if ($sent === null) {
            $this->setFailFlash(Craft::t('cleanair', 'An event handler cancelled the test report.'));
        } elseif ($sent === 0) {
            $this->setFailFlash($reason ?? Craft::t('cleanair', 'The test report could not be sent. Check the email settings and storage/logs/cleanair.log.'));
        } else {
            $this->setSuccessFlash(Craft::t('cleanair', 'Test report sent to {email}.', ['email' => $user->email]));
        }

        return $this->redirect($back);
    }

    /**
     * The saved filter, refused unless this user may schedule reports and change this filter.
     */
    private function savedFilter(int $filterId): SavedFilter
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->permissions->canScheduleReports()) {
            throw new ForbiddenHttpException(Craft::t('cleanair', 'You don’t have permission to schedule reports.'));
        }

        $saved = $plugin->savedFilters->getById($filterId);

        if (!$saved) {
            throw new NotFoundHttpException();
        }

        if (!$plugin->permissions->canWriteFilter($saved)) {
            throw new ForbiddenHttpException(Craft::t('cleanair', 'You don’t have permission to change that filter.'));
        }

        return $saved;
    }

    /**
     * The checks a form can't express: the format this edition allows, the delivery a personal
     * data type allows, and that both the person saving it and everyone on the list could run
     * the filter themselves.
     */
    private function checkReport(Report $report, SavedFilter $saved): void
    {
        $plugin = Plugin::getInstance();
        $report->validate();

        if (!isset($plugin->exports->availableFormats()[$report->format])) {
            $report->addError('format', Craft::t('cleanair', 'Choose a format.'));
        }

        if ($plugin->filters->type($saved->filter)->containsPersonalData()) {
            $report->delivery = Report::DELIVERY_LINK;
        }

        $owner = Craft::$app->getUser()->getIdentity();
        $reason = null;

        if ($owner && !$plugin->reports->canRun($saved, $owner, $reason)) {
            $report->addError('recipientIds', (string)$reason);
        }

        $users = $plugin->reports->users($report->recipientIds);
        [, $refused] = $plugin->reports->eligibleRecipients($saved, $users);

        foreach ($refused as $why) {
            $report->addError('recipientIds', $why);
        }

        // Users deleted since they were chosen just fall off the list.
        $report->recipientIds = array_values(array_map(fn($user) => (int)$user->id, $users));
    }
}
