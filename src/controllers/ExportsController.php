<?php

namespace justinholtweb\cleanair\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use justinholtweb\cleanair\Plugin;
use justinholtweb\cleanair\records\ExportRecord;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Finished and in-progress exports.
 *
 * An export file is a snapshot of real content, often personal data, sitting on disk. It
 * belongs to the person who asked for it, is downloadable only by them or an admin, and is
 * pruned on a schedule.
 */
class ExportsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        if (!Plugin::getInstance()->permissions->canExport()) {
            throw new ForbiddenHttpException(Craft::t('cleanair', 'You don’t have permission to export from Clean Air.'));
        }

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $userId = Craft::$app->getUser()->getId();

        return $this->renderTemplate('cleanair/exports/index', [
            'plugin' => $plugin,
            'exports' => $plugin->exports->forUser($userId),
            'retentionDays' => $plugin->getSettings()->exportRetentionDays,
        ]);
    }

    public function actionDownload(int $id): Response
    {
        $record = $this->record($id);

        if ($record->status !== ExportRecord::STATUS_DONE || !$record->path || !is_file($record->path)) {
            throw new NotFoundHttpException(Craft::t('cleanair', 'That export isn’t ready.'));
        }

        return Craft::$app->getResponse()->sendFile($record->path, $record->filename, [
            'mimeType' => match ($record->format) {
                'json' => 'application/json',
                'xml' => 'application/xml',
                'tsv' => 'text/tab-separated-values',
                default => 'text/csv',
            },
        ]);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        $record = $this->record((int)Craft::$app->getRequest()->getRequiredBodyParam('id'));

        Plugin::getInstance()->exports->delete($record);
        $this->setSuccessFlash(Craft::t('cleanair', 'Export deleted.'));

        return $this->redirect(UrlHelper::cpUrl('cleanair/exports'));
    }

    private function record(int $id): ExportRecord
    {
        $record = Plugin::getInstance()->exports->getById($id);

        if (!$record) {
            throw new NotFoundHttpException();
        }

        $user = Craft::$app->getUser()->getIdentity();

        if (!$user || (!$user->admin && $record->userId !== $user->id)) {
            throw new ForbiddenHttpException(Craft::t('cleanair', 'That export isn’t yours.'));
        }

        return $record;
    }
}
