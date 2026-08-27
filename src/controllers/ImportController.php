<?php

namespace justinholtweb\cleanair\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use justinholtweb\cleanair\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Importing saved filters from CP Filters.
 *
 * Always a preview first. An import that writes before you've seen what it will do is how a
 * migration turns into a cleanup job.
 */
class ImportController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        if (!Plugin::getInstance()->permissions->canImport()) {
            throw new ForbiddenHttpException(Craft::t('cleanair', 'You don’t have permission to import filters.'));
        }

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $includeDeleted = (bool)Craft::$app->getRequest()->getParam('includeDeleted');

        return $this->renderTemplate('cleanair/import/index', [
            'plugin' => $plugin,
            'available' => $plugin->importer->isAvailable(),
            'rows' => $plugin->importer->isAvailable() ? $plugin->importer->preview($includeDeleted) : [],
            'includeDeleted' => $includeDeleted,
            'canShare' => $plugin->permissions->canShare(),
            'report' => Craft::$app->getSession()->get('cleanair.importReport'),
        ]);
    }

    public function actionRun(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $ids = $request->getBodyParam('ids');

        $report = Plugin::getInstance()->importer->import([
            'ids' => is_array($ids) && $ids ? array_map('intval', $ids) : null,
            'includeDeleted' => (bool)$request->getBodyParam('includeDeleted'),
            'share' => (bool)$request->getBodyParam('share'),
            'skipExisting' => (bool)$request->getBodyParam('skipExisting', true),
            'importSettings' => (bool)$request->getBodyParam('importSettings'),
        ]);

        Craft::$app->getSession()->set('cleanair.importReport', $report);

        $this->setSuccessFlash(Craft::t('cleanair', '{imported} imported, {skipped} skipped, {failed} failed.', [
            'imported' => $report['imported'],
            'skipped' => $report['skipped'],
            'failed' => $report['failed'],
        ]));

        return $this->redirect(UrlHelper::cpUrl('cleanair/import'));
    }
}
